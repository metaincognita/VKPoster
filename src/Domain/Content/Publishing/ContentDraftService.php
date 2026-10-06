<?php

declare(strict_types=1);

namespace App\Domain\Content\Publishing;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Media\Media;
use App\Domain\Media\MediaService;
use App\Domain\Post\Post;
use App\Domain\Post\PostDraft;
use App\Domain\Post\PostOptions;
use App\Domain\Post\PostRepository;
use App\Domain\Post\PostService;
use App\Domain\Source\Source;
use App\Domain\Workspace\Permissions;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Throwable;

/** One-way material export into ordinary PostService drafts; immutable provenance, sanitized library files and revision idempotency. */
final class ContentDraftService
{
    public function __construct(private readonly Connection $db, private readonly MaterialRepository $materials, private readonly PostService $posts, private readonly PostRepository $repository, private readonly MediaService $media, private readonly MediaStorage $storage, private readonly Permissions $permissions, private readonly ContentOriginGuard $guard, private readonly Clock $clock, private readonly AuditLog $audit, private readonly \App\Domain\Media\MediaLimits $limits, private readonly \App\Domain\Content\VideoProcessing\VideoWorkflow $videos)
    {
    }

    /** Current approved revision only. Repeated exports return the existing editable post without overwriting it. */
    public function create(WorkspaceContext $ctx, ?Source $source, string $itemId, string $revision, string $textVersion, string $videoVersion = ''): Post
    {
        if (!$this->permissions->allows($ctx->role, $source === null ? 'discovery.manage' : 'sources.manage') || !$this->permissions->allows($ctx->role, 'posts.draft')) {
            throw new HttpException(403, 'Forbidden');
        }
        /** @var list<Media> $created */
        $created = [];
        try {
            return $this->db->transaction(function () use ($ctx, $source, $itemId, $revision, $textVersion, $videoVersion, &$created): Post {
                if ($source === null) {
                    $this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]);
                } elseif ($this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $source->id]) === []) {
                    throw new HttpException(404, 'Not found');
                }
                $item = $this->materials->item($ctx, $source, $itemId);
                $this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $item['id']]);
                $selection = $this->materials->selection($ctx, $source, (int) $item['id']);
                $current = MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id']));
                if (($selection['selection_status'] ?? '') !== 'approved' || !hash_equals($current, $revision)) {
                    throw new HttpException(409, 'Создать черновик можно только из актуального принятого материала.');
                }
                $hash = MaterialRepository::selectionHash($selection);
                $key = hash('sha256', 'material:' . $item['id'] . ':' . $revision . ':' . $hash);
                $prior = $this->db->select('SELECT p.public_id FROM content_post_origins o LEFT JOIN posts p ON p.id = o.post_id AND p.workspace_id = o.workspace_id WHERE o.workspace_id = ? AND o.idempotency_key = ?', [$ctx->workspaceId, $key])[0] ?? null;
                if ($prior !== null) {
                    $post = $prior['public_id'] === null ? null : $this->repository->find($ctx, (string) $prior['public_id']);
                    if ($post === null || !$this->posts->canSee($ctx, $post)) {
                        throw new HttpException(409, 'Черновик этой ревизии уже создан и удалён либо недоступен.');
                    }
                    $this->guard->assertCurrent($post);
                    return $post;
                }
                $text = $this->db->select('SELECT * FROM source_text_processings WHERE workspace_id = ? AND source_id <=> ? AND item_id = ? AND public_id = ?', [$ctx->workspaceId, $source?->id, $item['id'], $textVersion])[0] ?? null;
                if ($text === null || !$this->matches($text, $revision, $hash) || $text['processed_text'] === null) {
                    throw new HttpException(409, 'Выберите готовый результат обработки актуального текста.');
                }
                $images = $videoVersion === '' ? $this->selectedImages($ctx, $source, (int) $item['id'], $revision, $hash) : [];
                $files = array_map(static fn (array $v): array => ['storage_key' => (string) $v['storage_key'], 'sha256' => (string) $v['sha256'], 'name' => 'source-photo.jpg'], $images);
                $video = null;
                if ($videoVersion !== '') {
                    $video = $this->db->select('SELECT * FROM source_video_generations WHERE workspace_id = ? AND source_id <=> ? AND item_id = ? AND public_id = ? AND selected = TRUE', [$ctx->workspaceId, $source?->id, $item['id'], $videoVersion])[0] ?? null;
                    if ($source === null || $video === null || !$this->matches($video, $revision, $hash) || !$this->videos->current($ctx, $source, $itemId, $video)) {
                        throw new HttpException(409, 'Выберите готовую актуальную версию видео.');
                    }
                    $result = json_decode((string) $video['result_json'], true, 32, JSON_THROW_ON_ERROR);
                    if ($result['demonstration'] !== false || !is_string($result['storage_key'])) {
                        throw new HttpException(409, 'Fake provider не создаёт видеофайл. Создайте черновик без видео.');
                    }
                    $files = [['storage_key' => $result['storage_key'], 'sha256' => null, 'name' => 'source-video.mp4']];
                }
                $mediaIds = [];
                foreach ($files as $file) {
                    $mediaIds[] = $this->importFile($ctx, $file, $created);
                }
                // Processed text is plain text; escape editor markup instead of silently interpreting source punctuation.
                $plain = (string) $text['processed_text'];
                $editorText = preg_replace('/([\\\\*_~\[\]()])/', '\\\\$1', $plain) ?? $plain;
                $post = $this->posts->saveDraft($ctx, null, new PostDraft($editorText, $mediaIds, new PostOptions(), false, []));
                $candidate = $source === null ? ($this->db->select('SELECT d.* FROM discovery_items d JOIN discovery_imports l ON l.discovery_item_id = d.id AND l.workspace_id = d.workspace_id WHERE l.workspace_id = ? AND l.material_id = ?', [$ctx->workspaceId, $item['id']])[0] ?? throw new HttpException(409, 'Discovery origin missing')) : null;
                $snapshot = ['discovery_revision' => $candidate === null ? null : \App\Domain\Source\Selection\SemanticSelection::candidateRevision($candidate), 'text' => $text['public_id'], 'text_settings_version' => (int) $text['settings_version'], 'text_settings' => json_decode((string) $text['settings_json'], true, 32, JSON_THROW_ON_ERROR), 'image_processing' => array_map(static fn (array $image): array => array_intersect_key($image, array_flip(['processing_public_id', 'settings_version', 'settings_json', 'revision_hash', 'selection_hash', 'message_id'])), $images), 'image_variants' => $video === null ? array_column($images, 'public_id') : [], 'video' => $video['public_id'] ?? null, 'video_settings' => $video === null ? null : json_decode((string) $video['settings_json'], true, 32, JSON_THROW_ON_ERROR), 'video_basis' => $video === null ? null : json_decode((string) $video['basis_json'], true, 32, JSON_THROW_ON_ERROR), 'media_ids' => $mediaIds];
                $this->db->table('content_post_origins')->insert(['workspace_id' => $ctx->workspaceId, 'item_id' => $item['id'], 'post_id' => $post->id, 'text_processing_id' => $text['id'], 'revision_hash' => $revision, 'selection_hash' => $hash, 'idempotency_key' => $key, 'snapshot_json' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'created_by' => $ctx->userId, 'created_at' => DbTime::format($this->clock->now())]);
                $this->audit->record('content.draft_created', $ctx->userId, 'post', $post->publicId, ['origin' => $source === null ? 'discovery' : 'source'], $ctx->workspaceId);
                return $post;
            });
        } catch (Throwable $e) {
            // SQL rollback removes only our new library rows; compensate their objects, never a deduplicated existing upload.
            foreach ($created as $file) {
                foreach ([$file->storageKey, $file->thumbKey] as $key) {
                    if ($key !== null) {
                        try {
                            $this->storage->delete($key);
                        } catch (Throwable) {
                            // Storage outages may leave unreachable objects for later garbage collection.
                        }
                    }
                }
            }
            throw $e;
        }
    }

    /** @return list<array<string,mixed>> */
    public function history(WorkspaceContext $ctx, int $itemId): array
    {
        $rows = $this->db->select('SELECT p.public_id, p.status, o.created_at FROM content_post_origins o LEFT JOIN posts p ON p.id = o.post_id AND p.workspace_id = o.workspace_id WHERE o.workspace_id = ? AND o.item_id = ? ORDER BY o.id DESC LIMIT 30', [$ctx->workspaceId, $itemId]);
        return array_values(array_filter($rows, function (array $row) use ($ctx): bool {
            $post = $row['public_id'] === null ? null : $this->repository->find($ctx, (string) $row['public_id']);
            return $post === null || $this->posts->canSee($ctx, $post);
        }));
    }

    /**
     * Only an actual selected, current archived video can appear as an attachable UI option.
     * @return array<string,string>
     */
    public function videoChoices(WorkspaceContext $ctx, ?Source $source, string $itemId): array
    {
        $choices = ['' => 'Без видео'];
        if ($source !== null) {
            $item = $this->materials->item($ctx, $source, $itemId);
            foreach ($this->db->select('SELECT * FROM source_video_generations WHERE workspace_id = ? AND source_id = ? AND item_id = ? AND selected = TRUE AND status = ?', [$ctx->workspaceId, $source->id, $item['id'], 'completed']) as $video) {
                $result = json_decode((string) $video['result_json'], true, 32, JSON_THROW_ON_ERROR);
                if ($result['demonstration'] === false && $this->videos->current($ctx, $source, $itemId, $video)) {
                    $choices[(string) $video['public_id']] = 'Выбранное видео · версия ' . $video['settings_version'];
                }
            }
        }
        return $choices;
    }

    /** @return list<array<string,mixed>> */
    private function selectedImages(WorkspaceContext $ctx, ?Source $source, int $itemId, string $revision, string $hash): array
    {
        if ($source === null) {
            return []; // Current Discovery Core provides text only.
        }
        $rows = $this->db->select('SELECT p.*, p.public_id AS processing_public_id, v.public_id AS variant_id, v.storage_key, v.sha256 FROM source_image_processings p JOIN source_image_variants v ON v.public_id = p.selected_variant AND v.processing_id = p.id AND v.workspace_id = p.workspace_id WHERE p.workspace_id = ? AND p.source_id = ? AND p.item_id = ? AND p.revision_hash = ? ORDER BY p.message_id, p.id DESC', [$ctx->workspaceId, $source->id, $itemId, $revision]);
        $selected = [];
        foreach ($rows as $row) {
            if (!isset($selected[(int) $row['message_id']])) {
                if (!$this->matches($row, $revision, $hash)) {
                    throw new HttpException(409, 'Выбранное изображение устарело. Выберите актуальную версию.');
                }
                $row['public_id'] = $row['variant_id'];
                $selected[(int) $row['message_id']] = $row;
            }
        }
        $photos = $this->materials->messages($ctx, $source, $itemId);
        $count = count(array_filter($photos, static fn (array $m): bool => (json_decode((string) ($m['media_json'] ?? 'null'), true)['kind'] ?? '') === 'photo'));
        if (count($selected) !== $count) {
            throw new HttpException(409, 'Сначала обработайте и выберите изображение для каждой фотографии материала.');
        }
        return array_values($selected);
    }

    /**
     * @param array<string,mixed> $file
     * @param list<Media> $created
     */
    private function importFile(WorkspaceContext $ctx, array $file, array &$created): string
    {
        $path = $this->media->newTempFile();
        try {
            $input = $this->storage->read((string) $file['storage_key']);
            $output = fopen($path, 'wb');
            if ($output === false) {
                fclose($input);
                throw new HttpException(503, 'Не удалось подготовить медиа.');
            }
            try {
                stream_copy_to_stream($input, $output, $this->limits->maxFileBytes + 1);
            } finally {
                fclose($input);
                fclose($output);
            }
            if ($file['sha256'] !== null && !hash_equals((string) $file['sha256'], (string) hash_file('sha256', $path))) {
                throw new HttpException(409, 'Файл изображения изменился. Повторите обработку.');
            }
            $result = $this->media->upload($ctx, $path, (string) $file['name']);
            if (!$result->duplicate) {
                $created[] = $result->media;
            }
            return $result->media->publicId;
        } finally {
            unlink($path);
        }
    }

    /** @param array<string,mixed>|null $row */
    private function matches(?array $row, string $revision, string $hash): bool
    {
        return $row !== null && $row['status'] === 'completed' && $row['revision_hash'] === $revision && $row['selection_hash'] === $hash;
    }
}
