<?php

declare(strict_types=1);

namespace App\Domain\Post;

use App\Domain\Audit\AuditLog;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitException;
use App\Domain\Channel\Channel;
use App\Domain\Channel\ChannelCredentials;
use App\Domain\Channel\ChannelRepository;
use App\Domain\Workspace\ChannelAccessRepository;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Social\Contracts\EditableAdapter;
use App\Integrations\Social\Contracts\PlatformError;
use App\Integrations\Social\Contracts\PublishResult;
use App\Integrations\Social\PlatformRegistry;
use App\Kernel\Database\Connection;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Everything a member can do with posts: save a draft, plan or publish now, move in time, return to drafts, cancel, duplicate, delete,
 * retry, settle an uncertain publication, edit a published post. Pages call these methods; the rules live here:
 *
 * - drafts need `posts.draft`; anything that sends or plans needs `posts.publish` (an author hands the draft to an editor);
 * - a restricted member (or a client) only works with the channels assigned to them;
 * - a post is planned only when every chosen channel accepts it (`PostValidator`), and the same check runs again on every move;
 * - a publication that is being sent, or whose outcome is unknown, is never replanned behind the person's back (a duplicate is worse).
 */
final class PostService
{
    private const MAX_TEXT = 20000;
    private const MAX_MEDIA = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly PostRepository $posts,
        private readonly PublicationRepository $publications,
        private readonly PublicationSystem $system,
        private readonly ChannelRepository $channels,
        private readonly ChannelAccessRepository $access,
        private readonly PostValidator $validator,
        private readonly PublishRequestBuilder $builder,
        private readonly Permissions $permissions,
        private readonly AuditLog $audit,
        private readonly Queue $queue,
        private readonly Clock $clock,
        private readonly PlatformRegistry $registry,
        private readonly ChannelCredentials $credentials,
        private readonly PublicationDeleter $deleter,
        private readonly Entitlements $entitlements,
        private readonly \App\Domain\Content\Publishing\ContentOriginGuard $origin,
    ) {
    }

    // ---- who may see and touch what -------------------------------------------------------------------------------

    /**
     * Channel ids the member may use (null = all).
     *
     * @return list<int>|null
     */
    public function allowedChannels(WorkspaceContext $context): ?array
    {
        return $this->access->allowed($context, $context->userId);
    }

    public function canSee(WorkspaceContext $context, Post $post): bool
    {
        // A post of another workspace is never visible, even if somebody hands the object over (defence in depth: lookups are scoped already).
        return $post->workspaceId === $context->workspaceId && $this->posts->visibleTo($context, $post, $this->allowedChannels($context));
    }

    /**
     * Whether the member may change this post's plan: editors and up always, authors only their own drafts.
     */
    public function canEdit(WorkspaceContext $context, Post $post): bool
    {
        if (!$this->canSee($context, $post)) {
            return false;
        }
        if ($this->permissions->allows($context->role, 'posts.publish')) {
            return true;
        }

        return $this->permissions->allows($context->role, 'posts.draft') && $post->authorId === $context->userId && $post->status === PostStatus::Draft;
    }

    // ---- saving and planning ---------------------------------------------------------------------------------------

    /**
     * Save as a draft (also: take a planned post back to drafts). Nothing is sent. `$audit` is off for the editor's autosave, which
     * would otherwise fill the journal with every few seconds of typing.
     *
     * @throws PostException
     */
    public function saveDraft(WorkspaceContext $context, ?Post $existing, PostDraft $draft, bool $audit = true): Post
    {
        $this->requirePermission($context, 'posts.draft');
        $channels = $this->resolveChannels($context, $draft);
        $this->assertContent($context, $draft);
        if ($existing !== null) {
            $this->assertEditable($context, $existing);
        }

        $post = $this->db->transaction(function () use ($context, $existing, $draft, $channels): Post {
            $post = $this->persist($context, $existing, $draft, $channels, PostStatus::Draft, null);
            $this->withdrawPlan($context, $post);

            return $post;
        });
        if ($audit) {
            $this->audit->record($existing === null ? 'post.created' : 'post.updated', $context->userId, 'post', $post->publicId, ['title' => $post->title(60)], $context->workspaceId);
        }

        return $this->posts->find($context, $post->publicId) ?? $post;
    }

    /**
     * What is wrong with the draft for each chosen channel (empty = it can be planned as it is). Writes nothing; the editor calls
     * it while the person types.
     *
     * @return array<string, list<string>> channel public id => problems
     * @throws PostException for a channel the member may not use or a file that is not in the library
     */
    public function problemsFor(WorkspaceContext $context, PostDraft $draft): array
    {
        $channels = $this->resolveChannels($context, $draft);
        $this->assertContent($context, $draft);

        return $this->collectProblems($context, $draft, $channels);
    }

    /**
     * Plan the post for `$at` (UTC), or publish it right away with `$now`.
     *
     * @throws PostException with the problems of each channel when the post cannot go out as it is
     */
    public function schedule(WorkspaceContext $context, ?Post $existing, PostDraft $draft, DateTimeImmutable $at, bool $now = false): Post
    {
        $this->requirePermission($context, 'posts.publish');
        if ($existing !== null) {
            $this->origin->assertCurrent($existing);
        }
        if (!$now && $at <= $this->clock->now()) {
            throw new PostException('Это время уже прошло. Выберите время в будущем.');
        }
        $channels = $this->resolveChannels($context, $draft);
        if ($channels === []) {
            throw new PostException('Выберите хотя бы один канал, куда опубликовать пост.', ['*' => ['Выберите хотя бы один канал.']]);
        }
        $this->assertContent($context, $draft);
        $this->assertAllValid($context, $draft, $channels);
        $this->assertDailyLimits($context, $channels, $at, $existing?->id);
        if ($existing !== null) {
            $this->assertEditable($context, $existing);
        }
        $this->assertPlanRoom($context, $at, $existing);

        $created = [];
        $post = $this->db->transaction(function () use ($context, $existing, $draft, $channels, $at, &$created): Post {
            $post = $this->persist($context, $existing, $draft, $channels, PostStatus::Scheduled, $at);
            $created = $this->planPublications($context, $post, $at);
            $this->system->syncPostStatus($post->id);

            return $post;
        });
        $this->dispatchNow($created, $now);
        $this->audit->record($now ? 'post.publish_now' : 'post.scheduled', $context->userId, 'post', $post->publicId, ['title' => $post->title(60), 'at' => DbTime::format($at)], $context->workspaceId);

        return $this->posts->find($context, $post->publicId) ?? $post;
    }

    /**
     * Move a planned post to another time (the calendar's drag and drop). Every channel validates the post again.
     *
     * @throws PostException
     */
    public function reschedule(WorkspaceContext $context, Post $post, DateTimeImmutable $at): Post
    {
        $this->requirePermission($context, 'posts.publish');
        $this->origin->assertCurrent($post);
        if ($at <= $this->clock->now()) {
            throw new PostException('Это время уже прошло. Выберите время в будущем.');
        }
        $this->assertEditable($context, $post);
        if ($post->status !== PostStatus::Scheduled) {
            throw new PostException('Перенести можно только запланированный пост.');
        }
        $variants = $this->posts->variants($context, $post);
        $problems = [];
        foreach ($variants as $variant) {
            $channel = $variant->channelId === null ? null : $this->channelById($context, $variant->channelId);
            if ($channel === null) {
                $problems[$variant->channelName] = ['Канал отключён.'];
                continue;
            }
            $found = $this->validator->problems($context, $variant->resolve($post), $channel, true);
            if ($found !== []) {
                $problems[$channel->publicId] = $found;
            }
        }
        if ($problems !== []) {
            throw new PostException('Пост нельзя перенести: ' . $this->firstProblem($problems), $problems);
        }
        $this->assertDailyLimits($context, array_values(array_filter(array_map(fn (PostVariant $v): ?Channel => $v->channelId === null ? null : $this->channelById($context, $v->channelId), $variants))), $at, $post->id);
        $this->assertPlanRoom($context, $at, $post);
        $this->db->transaction(function () use ($context, $post, $at): void {
            if ($this->posts->lock($context, $post) === null) {
                throw new PostException('Пост не найден.');
            }
            foreach ($this->publications->forPost($context, $post) as $publication) {
                if ($publication->status === PublicationStatus::Cancelled) {
                    continue;
                }
                if ($publication->status !== PublicationStatus::Queued || !$this->publications->reschedule($context, $publication, $at)) {
                    throw new PostException('Пост уже публикуется, перенести его нельзя.');
                }
            }
            $this->posts->setSchedule($context, $post, PostStatus::Scheduled, $at, $context->timezone);
        });
        $this->audit->record('post.rescheduled', $context->userId, 'post', $post->publicId, ['title' => $post->title(60), 'at' => DbTime::format($at)], $context->workspaceId);

        return $this->posts->find($context, $post->publicId) ?? $post;
    }

    /**
     * Cancel a planned post: it stays in the history as "cancelled".
     *
     * @throws PostException
     */
    public function cancel(WorkspaceContext $context, Post $post): void
    {
        $this->requirePermission($context, 'posts.publish');
        $this->assertEditable($context, $post);
        $this->db->transaction(function () use ($context, $post): void {
            $this->posts->lock($context, $post);
            foreach ($this->publications->forPost($context, $post) as $publication) {
                if ($publication->status === PublicationStatus::Sending) {
                    throw new PostException('Пост уже публикуется, отменить его нельзя.');
                }
                if ($publication->status === PublicationStatus::Queued) {
                    $this->publications->cancel($context, $publication, 'Отменено вручную.');
                }
            }
            $this->system->syncPostStatus($post->id);
        });
        $this->audit->record('post.cancelled', $context->userId, 'post', $post->publicId, ['title' => $post->title(60)], $context->workspaceId);
    }

    /**
     * A copy as a new draft (same text, files, options and channels, no date).
     *
     * @throws PostException
     */
    public function duplicate(WorkspaceContext $context, Post $post): Post
    {
        $this->requirePermission($context, 'posts.draft');
        if (!$this->canSee($context, $post)) {
            throw new PostException('Пост не найден.');
        }
        $allowed = $this->allowedChannels($context);
        $variants = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            $channel = $variant->channelId === null ? null : $this->channelById($context, $variant->channelId);
            if ($channel === null || ($allowed !== null && !in_array($channel->id, $allowed, true))) {
                continue;
            }
            $variants[] = new VariantInput($channel->publicId, $variant->text, $variant->mediaIds, $variant->options);
        }
        $this->origin->assertCurrent($post);
        $copy = $this->db->transaction(function () use ($context, $post, $variants): Post {
            $copy = $this->saveDraft($context, null, new PostDraft($post->baseText, $post->mediaIds, $post->options, $post->perNetwork, $variants));
            $this->origin->copy($post, $copy);
            return $copy;
        });
        $this->audit->record('post.duplicated', $context->userId, 'post', $copy->publicId, ['title' => $copy->title(60)], $context->workspaceId);

        return $copy;
    }

    /**
     * Delete a post that has not gone out. A published post stays in the history (it can be removed from the networks instead).
     *
     * @throws PostException
     */
    public function delete(WorkspaceContext $context, Post $post): void
    {
        if (!$this->canEdit($context, $post)) {
            throw new PostException('У вас нет права удалять этот пост.', [], true);
        }
        $this->db->transaction(function () use ($context, $post): void {
            $this->posts->lock($context, $post);
            foreach ($this->publications->forPost($context, $post) as $publication) {
                if (in_array($publication->status, [PublicationStatus::Sending, PublicationStatus::Sent, PublicationStatus::Unknown], true)) {
                    throw new PostException('Этот пост уже опубликован или публикуется, поэтому он остаётся в истории. Его можно удалить из соцсетей.');
                }
            }
            $this->posts->delete($context, $post);
        });
        $this->audit->record('post.deleted', $context->userId, 'post', $post->publicId, ['title' => $post->title(60)], $context->workspaceId);
    }

    // ---- publications ---------------------------------------------------------------------------------------------

    /**
     * Try a failed publication again.
     *
     * @throws PostException
     */
    public function retry(WorkspaceContext $context, Publication $publication): void
    {
        $this->requirePermission($context, 'posts.publish');
        $loaded = $this->system->load($publication);
        if ($loaded !== null) {
            $this->origin->assertCurrent($loaded['post']);
        }
        if (!in_array($publication->status, [PublicationStatus::Failed, PublicationStatus::Unknown], true)) {
            throw new PostException('Повторить можно только то, что не удалось опубликовать.');
        }
        $now = DbTime::format($this->clock->now());
        $moved = $this->system->move($publication->id, $publication->status, PublicationStatus::Queued, [
            'attempt' => 0, 'due_at' => $now, 'run_at' => $now, 'enqueued_at' => null, 'error_code' => null, 'error_message' => null, 'error_detail' => null,
        ]);
        if (!$moved) {
            throw new PostException('Состояние публикации уже изменилось. Обновите страницу.');
        }
        $this->system->syncPostStatus($publication->postId);
        $this->queue->dispatch(new PublishJob($publication->id), 0, PublishJob::QUEUE);
        $this->system->markEnqueued([$publication->id]);
        $this->audit->record('post.retried', $context->userId, 'publication', $publication->publicId, [], $context->workspaceId);
    }

    /**
     * The person has looked at a publication whose outcome was unknown and says what really happened: it went out (`sent`) or it did
     * not and should be dropped (`cancel`). Trying again is `retry()`.
     *
     * @throws PostException
     */
    public function settleUnknown(WorkspaceContext $context, Publication $publication, bool $wentOut): void
    {
        $this->requirePermission($context, 'posts.publish');
        if ($publication->status !== PublicationStatus::Unknown) {
            throw new PostException('Состояние публикации уже изменилось. Обновите страницу.');
        }
        $moved = $wentOut
            ? $this->system->move($publication->id, PublicationStatus::Unknown, PublicationStatus::Sent, ['sent_at' => DbTime::format($this->clock->now()), 'error_code' => null, 'error_message' => 'Отмечено вручную: пост вышел.'])
            : $this->system->move($publication->id, PublicationStatus::Unknown, PublicationStatus::Cancelled, ['error_message' => 'Отмечено вручную: пост не вышел.']);
        if (!$moved) {
            throw new PostException('Состояние публикации уже изменилось. Обновите страницу.');
        }
        $this->system->syncPostStatus($publication->postId);
        $this->audit->record($wentOut ? 'post.settled_sent' : 'post.settled_dropped', $context->userId, 'publication', $publication->publicId, [], $context->workspaceId);
    }

    /**
     * Delete a published post from its network now.
     *
     * @throws PostException
     */
    public function removeFromNetwork(WorkspaceContext $context, Publication $publication): void
    {
        $this->requirePermission($context, 'posts.publish');
        try {
            if (!$this->deleter->run($publication->id)) {
                throw new PostException('Удалить пост из соцсети не получилось. Подробности — в журнале публикации.');
            }
        } catch (PlatformError $e) {
            throw new PostException('Соцсеть сейчас не отвечает: ' . $e->forUser());
        }
        $this->audit->record('post.removed_from_network', $context->userId, 'publication', $publication->publicId, [], $context->workspaceId);
    }

    /**
     * Change the text of a published post in the networks that allow it, and in our record. Returns what happened per channel.
     *
     * @return list<array{channel: string, ok: bool, message: string}>
     * @throws PostException
     */
    public function editPublished(WorkspaceContext $context, Post $post, string $text): array
    {
        $this->requirePermission($context, 'posts.publish');
        if (!$this->canSee($context, $post)) {
            throw new PostException('Пост не найден.');
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > self::MAX_TEXT) {
            throw new PostException('Текст не может быть пустым или длиннее ' . self::MAX_TEXT . ' символов.');
        }
        $publications = array_values(array_filter($this->publications->forPost($context, $post), static fn (Publication $p): bool => $p->status === PublicationStatus::Sent && $p->deletedAt === null));
        if ($publications === []) {
            throw new PostException('У этого поста нет опубликованных копий, которые можно изменить.');
        }
        $variants = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            $variants[$variant->id] = $variant;
        }
        $report = [];
        foreach ($publications as $publication) {
            $variant = $variants[$publication->variantId] ?? null;
            $channel = $publication->channelId === null ? null : $this->channelById($context, $publication->channelId);
            if ($variant === null || $channel === null || !$this->registry->isEnabled($channel->platform)) {
                $report[] = ['channel' => $variant === null ? 'Канал' : $variant->channelName, 'ok' => false, 'message' => 'Канал недоступен.'];
                continue;
            }
            $adapter = $this->registry->adapter($channel->platform);
            if (!$adapter instanceof EditableAdapter) {
                $report[] = ['channel' => $channel->displayName(), 'ok' => false, 'message' => $channel->platform->label() . ' не позволяет менять опубликованные посты.'];
                continue;
            }
            $newText = $variant->text !== null ? $variant->text : $text;
            $resolved = new ResolvedVariant($variant, $newText, $variant->mediaIds ?? $post->mediaIds, $variant->options ?? $post->options);
            try {
                $built = $this->builder->build($context, $resolved, $adapter->capabilities(), false);
                $problems = $adapter->validate($built->request);
                if ($problems !== []) {
                    $report[] = ['channel' => $channel->displayName(), 'ok' => false, 'message' => implode(' ', $problems)];
                    continue;
                }
                $adapter->edit(new PublishResult((string) $publication->externalPostId, $publication->externalUrl, $publication->externalIds), $channel->externalId, $this->credentials->forChannel($channel), $built->request, $resolved->mediaIds !== []);
                $report[] = ['channel' => $channel->displayName(), 'ok' => true, 'message' => 'Текст изменён.'];
            } catch (PlatformError $e) {
                $report[] = ['channel' => $channel->displayName(), 'ok' => false, 'message' => $e->forUser()];
            } catch (PostException $e) {
                $report[] = ['channel' => $channel->displayName(), 'ok' => false, 'message' => $e->getMessage()];
            }
        }
        $this->posts->updateContent($context, $post, $text, $post->mediaIds, $post->options, $post->perNetwork);
        $this->audit->record('post.edited_published', $context->userId, 'post', $post->publicId, ['title' => $post->title(60)], $context->workspaceId);

        return $report;
    }

    // ---- internals ------------------------------------------------------------------------------------------------

    /**
     * @param list<Channel> $channels
     */
    private function persist(WorkspaceContext $context, ?Post $existing, PostDraft $draft, array $channels, PostStatus $status, ?DateTimeImmutable $at): Post
    {
        if ($existing === null) {
            $post = $this->posts->create($context, $context->userId, $draft->text, $draft->mediaIds, $draft->options, $draft->perNetwork, $status, $at, $context->timezone);
        } else {
            $post = $this->posts->lock($context, $existing) ?? throw new PostException('Пост не найден.');
            $this->assertEditable($context, $post);
            $this->posts->updateContent($context, $post, $draft->text, $draft->mediaIds, $draft->options, $draft->perNetwork);
            $this->posts->setSchedule($context, $post, $status, $at, $context->timezone);
        }
        $this->syncVariants($context, $post, $draft, $channels);

        return $this->posts->find($context, $post->publicId) ?? $post;
    }

    /**
     * @param list<Channel> $channels
     */
    private function syncVariants(WorkspaceContext $context, Post $post, PostDraft $draft, array $channels): void
    {
        $byChannel = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            if ($variant->channelId !== null) {
                $byChannel[$variant->channelId] = $variant;
            }
        }
        $inputs = [];
        foreach ($draft->variants as $input) {
            $inputs[strtoupper($input->channelId)] = $input;
        }
        $keep = [];
        foreach ($channels as $channel) {
            $input = $inputs[strtoupper($channel->publicId)] ?? null;
            $custom = $draft->perNetwork && $input !== null;
            $text = $custom ? $input->text : null;
            $media = $custom ? $input->mediaIds : null;
            $options = $custom ? $input->options : null;
            $keep[$channel->id] = true;
            if (isset($byChannel[$channel->id])) {
                $this->posts->updateVariant($context, $byChannel[$channel->id], $text, $media, $options);
            } else {
                $this->posts->addVariant($context, $post, $channel->id, $channel->platform, $channel->displayName(), $text, $media, $options);
            }
        }
        foreach ($byChannel as $channelId => $variant) {
            if (isset($keep[$channelId])) {
                continue;
            }
            $latest = $this->publications->latestFor($context, $variant);
            if ($latest !== null && in_array($latest->status, [PublicationStatus::Sending, PublicationStatus::Sent, PublicationStatus::Unknown], true)) {
                throw new PostException('Канал «' . $variant->channelName . '» нельзя убрать из поста: в него уже отправлено или отправляется.');
            }
            $this->posts->deleteVariant($context, $variant);
        }
    }

    /**
     * Make every variant's publication match the plan: new ones queued, queued ones moved, and refuse where a post may already be out.
     *
     * @return list<Publication> the publications that are queued after this
     */
    private function planPublications(WorkspaceContext $context, Post $post, DateTimeImmutable $at): array
    {
        $queued = [];
        foreach ($this->posts->variants($context, $post) as $variant) {
            $latest = $this->publications->latestFor($context, $variant);
            if ($latest === null || in_array($latest->status, [PublicationStatus::Cancelled, PublicationStatus::Failed], true)) {
                $queued[] = $this->publications->createQueued($context, $post, $variant, $at);
            } elseif ($latest->status === PublicationStatus::Queued) {
                $this->publications->reschedule($context, $latest, $at);
                $queued[] = $this->publications->find($context, $latest->publicId) ?? $latest;
            } elseif ($latest->status === PublicationStatus::Sending) {
                throw new PostException('Пост уже публикуется, изменить его сейчас нельзя.');
            } elseif ($latest->status === PublicationStatus::Unknown) {
                throw new PostException('В «' . $variant->channelName . '» мы не уверены, вышел ли пост. Откройте пост, проверьте канал и отметьте результат, потом планируйте заново.');
            }
        }

        return $queued;
    }

    /**
     * "To drafts": publications that were only a plan are removed, the others (with a history) are cancelled.
     */
    private function withdrawPlan(WorkspaceContext $context, Post $post): void
    {
        foreach ($this->publications->forPost($context, $post) as $publication) {
            if ($publication->status === PublicationStatus::Sending) {
                throw new PostException('Пост уже публикуется, вернуть его в черновики нельзя.');
            }
            if ($publication->status === PublicationStatus::Queued) {
                $this->publications->cancel($context, $publication, 'Пост возвращён в черновики.');
            }
        }
        $this->system->syncPostStatus($post->id);
    }

    /**
     * @param list<Publication> $publications
     */
    private function dispatchNow(array $publications, bool $now): void
    {
        if (!$now) {
            return; // the scheduler creates the jobs shortly before the planned time
        }
        $ids = [];
        foreach ($publications as $publication) {
            $this->queue->dispatch(new PublishJob($publication->id), 0, PublishJob::QUEUE);
            $ids[] = $publication->id;
        }
        $this->system->markEnqueued($ids);
    }

    /**
     * @return list<Channel> the chosen channels, each once, in the order given
     * @throws PostException for an unknown channel, or one the member may not use
     */
    private function resolveChannels(WorkspaceContext $context, PostDraft $draft): array
    {
        $allowed = $this->allowedChannels($context);
        $channels = [];
        foreach ($draft->variants as $input) {
            $channel = $this->channels->find($context, $input->channelId);
            if ($channel === null || ($allowed !== null && !in_array($channel->id, $allowed, true))) {
                throw new PostException('Один из выбранных каналов не найден или недоступен вам.');
            }
            $channels[$channel->id] = $channel;
        }

        return array_values($channels);
    }

    /**
     * @throws PostException
     */
    private function assertContent(WorkspaceContext $context, PostDraft $draft): void
    {
        if (mb_strlen($draft->text) > self::MAX_TEXT) {
            throw new PostException('Текст слишком длинный: не больше ' . self::MAX_TEXT . ' символов.');
        }
        $ids = $draft->mediaIds;
        foreach ($draft->variants as $input) {
            $ids = [...$ids, ...($input->mediaIds ?? [])];
            if ($input->text !== null && mb_strlen($input->text) > self::MAX_TEXT) {
                throw new PostException('Текст слишком длинный: не больше ' . self::MAX_TEXT . ' символов.');
            }
        }
        if (count($draft->mediaIds) > self::MAX_MEDIA) {
            throw new PostException('К посту можно прикрепить не больше ' . self::MAX_MEDIA . ' файлов.');
        }
        $this->builder->mediaFor($context, array_values(array_unique($ids)));
    }

    /**
     * The plan allows a number of posts per month. A post that is already counted (it is being edited or moved) costs nothing again
     * unless it moves into a month that is full.
     *
     * @throws PostException
     */
    private function assertPlanRoom(WorkspaceContext $context, DateTimeImmutable $at, ?Post $existing): void
    {
        $counted = $existing !== null && Entitlements::countsTowardMonthlyLimit($existing->status->value) ? $existing->scheduledAt : null;
        try {
            $this->entitlements->assertCanPlanPost($context->workspaceId, $at, $counted);
        } catch (PlanLimitException $e) {
            throw new PostException($e->getMessage(), planLimit: true);
        }
    }

    /**
     * Networks cap how many posts a channel may publish a day (VK: about 50). Planning past the cap would fail on the day, so it is refused now,
     * counting the posts already planned for the same calendar day (in the workspace's time zone).
     *
     * @param list<Channel> $channels
     * @throws PostException
     */
    private function assertDailyLimits(WorkspaceContext $context, array $channels, DateTimeImmutable $at, ?int $exceptPostId): void
    {
        $zone = new \DateTimeZone($context->timezone);
        $start = $at->setTimezone($zone)->setTime(0, 0);
        $end = $start->modify('+1 day');
        $problems = [];
        foreach ($channels as $channel) {
            if (!$this->registry->isEnabled($channel->platform)) {
                continue;
            }
            $limit = $this->registry->adapter($channel->platform)->capabilities()->maxPostsPerDay;
            if ($limit <= 0) {
                continue;
            }
            $planned = $this->publications->countForChannelBetween($context, $channel->id, $start, $end, $exceptPostId);
            if ($planned >= $limit) {
                $problems[$channel->publicId] = [sprintf('«%s»: на %s уже запланировано %d из %d постов, которые %s разрешает в сутки. Выберите другой день.', $channel->displayName(), $start->format('d.m.Y'), $planned, $limit, $channel->platform->label())];
            }
        }
        if ($problems !== []) {
            throw new PostException('Пост нельзя запланировать: ' . $this->firstProblem($problems), $problems);
        }
    }

    /**
     * @param list<Channel> $channels
     * @throws PostException with the problems of every channel
     */
    private function assertAllValid(WorkspaceContext $context, PostDraft $draft, array $channels): void
    {
        $problems = $this->collectProblems($context, $draft, $channels);
        if ($problems !== []) {
            throw new PostException('Пост пока нельзя запланировать: ' . $this->firstProblem($problems), $problems);
        }
    }

    /**
     * @param list<Channel> $channels
     * @return array<string, list<string>>
     */
    private function collectProblems(WorkspaceContext $context, PostDraft $draft, array $channels): array
    {
        $inputs = [];
        foreach ($draft->variants as $input) {
            $inputs[strtoupper($input->channelId)] = $input;
        }
        $problems = [];
        foreach ($channels as $channel) {
            $input = $inputs[strtoupper($channel->publicId)] ?? null;
            $custom = $draft->perNetwork && $input !== null;
            $variant = new PostVariant(0, 0, $channel->id, $channel->platform, $channel->displayName(), $custom ? $input->text : null, $custom ? $input->mediaIds : null, $custom ? $input->options : null);
            $resolved = new ResolvedVariant($variant, $variant->text ?? $draft->text, $variant->mediaIds ?? $draft->mediaIds, $variant->options ?? $draft->options);
            $found = $this->validator->problems($context, $resolved, $channel, true);
            if ($found !== []) {
                $problems[$channel->publicId] = $found;
            }
        }

        return $problems;
    }

    /**
     * @throws PostException
     */
    private function assertEditable(WorkspaceContext $context, Post $post): void
    {
        if (!$this->canEdit($context, $post)) {
            throw new PostException('У вас нет права менять этот пост.', [], true);
        }
        if (!$post->status->isEditablePlan()) {
            throw new PostException($post->status === PostStatus::Publishing ? 'Пост сейчас публикуется, изменить его нельзя.' : 'Этот пост уже опубликован, поэтому его план изменить нельзя.');
        }
    }

    /**
     * @throws PostException
     */
    private function requirePermission(WorkspaceContext $context, string $permission): void
    {
        if (!$this->permissions->allows($context->role, $permission)) {
            throw new PostException($permission === 'posts.publish' ? 'Планировать и публиковать посты могут редакторы и администраторы. Сохраните пост как черновик, и редактор его опубликует.' : 'У вас нет права работать с постами.', [], true);
        }
    }

    /**
     * @param array<string, list<string>> $problems
     */
    private function firstProblem(array $problems): string
    {
        foreach ($problems as $list) {
            if ($list !== []) {
                return $list[0];
            }
        }

        return 'проверьте поля.';
    }

    private function channelById(WorkspaceContext $context, int $id): ?Channel
    {
        return $this->channels->findById($context, $id);
    }
}
