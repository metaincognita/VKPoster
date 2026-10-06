<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\Publishing\ContentDraftService;
use App\Domain\Content\Publishing\ContentOriginGuard;
use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Content\VideoProcessing\VideoWorkflow;
use App\Domain\ContentDiscovery\ContentDiscovery;
use App\Domain\ContentDiscovery\DiscoveryMaterialGateway;
use App\Domain\Post\PostException;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Source;
use App\Domain\Source\SourceIngress;
use App\Domain\Source\SourceService;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\PostTestCase;
use App\Tests\Support\SourceImageFixture;
use App\Tests\Support\TestEnv;

/** Full synthetic content-to-existing-publisher flow. No Telegram, AI or social service is contacted. */
final class ContentDraftTest extends PostTestCase
{
    protected function tearDown(): void
    {
        // Legacy DDL rollback tests recreate their original tables; restore additive review columns.
        (require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000032_review_fixes.php')->up(\App\Tests\Support\TestEnv::connection());
        (require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000033_independent_review.php')->up(\App\Tests\Support\TestEnv::connection());
        parent::tearDown();
    }

    /** @return array{WorkspaceContext,Source,array<string,mixed>,string,string} */
    public function fixture(bool $approved = true, bool $photos = false): array
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Draft source', 'telegram', '@draft_fixture', true);
        $messages = [];
        foreach ($photos ? [10, 11] : [10] as $id) {
            $messages[] = ['channel_id' => '12345', 'message_id' => $id, 'grouped_id' => $photos ? '777' : null, 'text' => 'Original **literal** _text_', 'entities' => [], 'media' => $photos ? ['kind' => 'photo', 'telegram_id' => (string) (1000 + $id), 'selected' => ['type' => 'w', 'width' => 640, 'height' => 640, 'bytes' => 10000]] : null, 'forward' => null, 'date' => '2026-10-05T10:00:00Z', 'edit_date' => null, 'content_hash' => hash('sha256', 'original' . $id)];
        }
        $payload = ['peer_id' => '12345', 'grouped_id' => $photos ? '777' : null, 'messages' => $messages];
        $c->get(SourceIngress::class)->accept(['version' => 1, 'source_id' => $source->publicId, 'kind' => 'item', 'payload' => $payload, 'event_id' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException('Missing item');
        if ($approved) {
            $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        }
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $text = '';
        if ($approved) {
            $c->get(ContentProcessor::class)->process($ctx, $source, (string) $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'unchanged']));
            $text = (string) ($this->db->table('source_text_processings')->first() ?? throw new \LogicException('Missing text'))['public_id'];
        }
        $this->actAs($owner);
        $this->fakeChannel($ws, $owner);
        return [$ctx, $source, $item, $revision, $text];
    }

    public function testCreateEditIdempotencyAndExistingSchedulerQueuePublisher(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $exports = $c->get(ContentDraftService::class);
        $post = $exports->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        self::assertSame('draft', $post->status->value);
        self::assertSame((string) $item['text'], \App\Domain\Post\TextFormatter::toPlain($post->baseText));
        $edited = $this->service()->saveDraft($ctx, $post, $this->draft('Edited in ordinary editor'));
        self::assertSame($post->id, $exports->create($ctx, $source, (string) $item['public_id'], $revision, 'ignored retry input')->id);
        self::assertSame('Edited in ordinary editor', $this->posts()->find($ctx, $post->publicId)?->baseText);
        self::assertSame(1, $this->db->table('content_post_origins')->count());
        $channel = $this->app->container()->get(\App\Domain\Channel\ChannelRepository::class)->all($ctx)[0];
        $planned = $this->service()->schedule($ctx, $edited, $this->draft($edited->baseText, [$channel]), $this->in('+1 minute'));
        $this->clock->advance(61);
        self::assertSame(1, $this->drain());
        self::assertSame('published', $this->posts()->find($ctx, $planned->publicId)?->status->value);
        self::assertCount(1, $this->fake->published);
        self::assertSame('Edited in ordinary editor', $this->fake->requests[0]->text);
        self::assertSame($item['text'], ($this->db->table('source_items')->first() ?? throw new \LogicException('Missing item'))['text']);
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'];
        self::assertStringContainsString('Открыть черновик', $this->get($url)->body);
        self::assertStringContainsString('Опубликован', $this->get($url)->body);
    }

    public function testAlbumSelectedArchivesBecomeLibraryFilesAndPublishThroughExistingBuilder(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture(photos: true);
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, (string) $item['public_id'], $revision);
        foreach ($workflow->jobs() as $job) {
            $bytes = SourceImageFixture::bytes(different: $job['message_id'] === 11);
            $workflow->accept(array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['sha256' => hash('sha256', $bytes), 'data' => base64_encode($bytes)]);
        }
        $post = $this->app->container()->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        self::assertCount(2, $post->mediaIds);
        self::assertSame(2, $this->db->table('media')->count());
        $channel = $this->app->container()->get(\App\Domain\Channel\ChannelRepository::class)->all($ctx)[0];
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel], $post->mediaIds), $this->clock->now(), true);
        $this->drain();
        self::assertSame(2, $this->fake->published[0]['media']);
        self::assertSame(2, $this->db->table('source_image_variants')->where('kind', '=', 'original')->count());
    }

    public function testRejectionAfterSchedulingBlocksPublisherAndPreservesDuplicateGuard(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $post = $this->app->container()->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $copy = $this->service()->duplicate($ctx, $post);
        self::assertSame(2, $this->db->table('content_post_origins')->count());
        $channel = $this->app->container()->get(\App\Domain\Channel\ChannelRepository::class)->all($ctx)[0];
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel]), $this->clock->now(), true);
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], false);
        $this->drain();
        self::assertCount(0, $this->fake->published);
        self::assertSame('origin_stale', ($this->db->table('publications')->first() ?? throw new \LogicException('Missing publication'))['error_code']);
        self::assertNotNull($this->app->container()->get(ContentOriginGuard::class)->problem($copy));
        $this->expectException(PostException::class);
        $this->service()->schedule($ctx, $copy, $this->draft('edited', [$channel]), $this->clock->now(), true);
    }

    public function testStaleRevisionNoDecisionAndUnselectedPhotosCannotCreateDrafts(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture(photos: true);
        $exports = $this->app->container()->get(ContentDraftService::class);
        foreach (['old', $revision] as $requested) {
            try {
                $exports->create($ctx, $source, (string) $item['public_id'], $requested, $text);
                self::fail('Should reject outdated revision or missing image selection');
            } catch (HttpException $e) {
                self::assertSame(409, $e->status);
            }
        }
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], false);
        self::assertSame(0, $this->db->table('posts')->count());
        $this->expectException(HttpException::class);
        $exports->create($ctx, $source, (string) $item['public_id'], $revision, $text);
    }

    public function testSourceEditInvalidatesScheduledDraftAndNewRevisionCreatesNewDraft(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $old = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->db->execute('UPDATE source_items SET text = ? WHERE workspace_id = ? AND id = ?', ['New revision', $ctx->workspaceId, $item['id']]);
        self::assertNotNull($c->get(ContentOriginGuard::class)->problem($old));
        $updated = $c->get(MaterialRepository::class)->item($ctx, $source, (string) $item['public_id']);
        $newRevision = MaterialRepository::revision($updated, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $c->get(ContentProcessor::class)->process($ctx, $source, (string) $item['public_id'], $newRevision, TextSettings::fromInput([]));
        $newText = $this->db->select('SELECT public_id FROM source_text_processings ORDER BY id DESC')[0]['public_id'];
        $new = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $newRevision, (string) $newText);
        self::assertNotSame($old->id, $new->id);
        self::assertNull($c->get(ContentOriginGuard::class)->problem($new));
    }

    public function testDiscoveryImportedApprovedProcessedMaterialCreatesOrdinaryPost(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $c->get(ContentDiscovery::class)->refresh($ctx);
        $candidate = $this->db->table('discovery_items')->first() ?? throw new \LogicException('Missing candidate');
        $id = $c->get(DiscoveryMaterialGateway::class)->import($ctx, (string) $candidate['public_id']);
        $c->get(DiscoveryMaterialGateway::class)->decide($ctx, $id, true);
        $item = $c->get(MaterialRepository::class)->item($ctx, null, $id);
        $revision = MaterialRepository::revision($item, []);
        $c->get(ContentProcessor::class)->process($ctx, null, $id, $revision, TextSettings::fromInput([]));
        $text = (string) ($this->db->table('source_text_processings')->first() ?? throw new \LogicException('Missing text'))['public_id'];
        $post = $c->get(ContentDraftService::class)->create($ctx, null, $id, $revision, $text);
        $channel = $this->fakeChannel($ws, $owner);
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel]), $this->clock->now(), true);
        $this->drain();
        self::assertCount(1, $this->fake->published);
        self::assertSame(0, $this->db->table('sources')->count());
        $this->actAs($owner);
        self::assertStringContainsString('Открыть черновик', $this->get('/w/' . $ctx->workspacePublicId . '/radar/materials/' . $id)->body);
    }

    public function testFakeVideoCannotBeAttachedButTextDraftWithoutVideoIsAllowed(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $workflow = $c->get(VideoWorkflow::class);
        $job = $workflow->request($ctx, $source, (string) $item['public_id'], $revision, VideoSettings::fromInput([]));
        $workflow->run($ctx, $source, (string) $item['public_id'], $job);
        $workflow->choose($ctx, $source, (string) $item['public_id'], $job);
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text, $job);
            self::fail('Fake has no real video');
        } catch (HttpException $e) {
            self::assertStringContainsString('Fake provider', $e->getMessage());
        }
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertSame('draft', $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text)->status->value);
    }

    public function testHttpCreationCsrfAndWorkspaceAndRoleIsolation(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'];
        self::assertStringContainsString('Создать черновик', $this->get($url)->body);
        $response = $this->post($url . '/draft', ['revision' => $revision, 'text_version' => $text]);
        self::assertStringContainsString('/posts/', (string) $response->header('Location'));
        self::assertStringContainsString('/edit', (string) $response->header('Location'));
        self::assertSame(200, $this->get((string) $response->header('Location'))->status);
        self::assertSame(419, $this->request('POST', $url . '/draft', ['revision' => $revision])->status);
        [$outsider] = $this->ownerWithWorkspace('outside@example.com');
        $this->actAs($outsider);
        self::assertSame(404, $this->post($url . '/draft', ['revision' => $revision, 'text_version' => $text])->status);
        $ws = $this->app->container()->get(\App\Domain\Workspace\WorkspaceRepository::class)->findById($ctx->workspaceId) ?? throw new \LogicException('Missing workspace');
        $viewer = $this->memberOf($ws, 'view@example.com', Role::Viewer);
        $this->actAs($viewer);
        self::assertSame(403, $this->post($url . '/draft', ['revision' => $revision, 'text_version' => $text])->status);
        $this->expectException(HttpException::class);
        $this->app->container()->get(ContentDraftService::class)->create($this->contextFor($ws, $viewer), $source, (string) $item['public_id'], $revision, $text);
    }

    public function testDeletedDraftIsNotSilentlyRecreatedAndMigrationRollbackReplay(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->service()->delete($ctx, $post);
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
            self::fail('Must retain the deleted draft idempotency tombstone');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $migration->down($this->db);
        $migration->up($this->db);
        self::assertSame(0, $this->db->table('content_post_origins')->count());
        self::assertSame(1, $this->db->table('source_items')->count());
    }

    /** @return list<array{\App\Integrations\Social\Contracts\Platform}> */
    public static function destinationPlatforms(): array
    {
        return [[\App\Integrations\Social\Contracts\Platform::Telegram], [\App\Integrations\Social\Contracts\Platform::Vk], [\App\Integrations\Social\Contracts\Platform::Max]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('destinationPlatforms')]
    public function testProcessedSourceReachesExistingRealAdapterWithOnlyHttpMocked(\App\Integrations\Social\Contracts\Platform $platform): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $ws = $c->get(\App\Domain\Workspace\WorkspaceRepository::class)->findById($ctx->workspaceId) ?? throw new \LogicException('Missing workspace');
        $owner = $c->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException('Missing user');
        if ($platform === \App\Integrations\Social\Contracts\Platform::Vk) {
            $credential = $c->get(\App\Domain\Channel\CredentialVault::class)->storeOAuth($ctx, $platform, \App\Tests\Support\VkFixtures::TOKEN, 'test-refresh-not-real', $this->in('+1 day'), 'device', 'account', 'wall');
            $channel = $this->makeChannel($ws, $owner, '123', mode: \App\Domain\Channel\ChannelMode::Account, credentialId: $credential, platform: $platform);
            $this->http->expect('POST', \App\Tests\Support\VkFixtures::API . 'wall.post', 200, \App\Tests\Support\VkFixtures::raw('wall_post'));
        } elseif ($platform === \App\Integrations\Social\Contracts\Platform::Telegram) {
            $channel = $this->makeChannel($ws, $owner, platform: $platform);
            $this->tg('sendMessage', 'send_message');
        } else {
            $channel = $this->makeChannel($ws, $owner, (string) \App\Tests\Support\MaxFixtures::CHANNEL, platform: $platform);
            $this->max('POST', '/messages', 'message_sent');
        }
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel]), $this->clock->now(), true);
        $this->drain();
        self::assertSame('published', $this->posts()->find($ctx, $post->publicId)?->status->value);
        self::assertCount(1, $this->http->requests);
        $this->http->assertAllConsumed();
        self::assertCount(0, $this->fake->published);
    }

    public function testNeedsReviewAndWrongTextAndDeletedOriginAreBlocked(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture(approved: false);
        $c = $this->app->container();
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, 'no text');
            self::fail('Needs review cannot export');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, 'missing text');
            self::fail('Missing processing result cannot export');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $c->get(ContentProcessor::class)->process($ctx, $source, (string) $item['public_id'], $revision, TextSettings::fromInput([]));
        $text = (string) ($this->db->table('source_text_processings')->first() ?? throw new \LogicException('Missing text'))['public_id'];
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->db->table('source_items')->where('id', '=', $item['id'])->delete();
        self::assertNotNull($c->get(ContentOriginGuard::class)->problem($post));
    }

    public function testFailedMediaDigestRollsBackWholeExport(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture(photos: true);
        $c = $this->app->container();
        $workflow = $c->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, (string) $item['public_id'], $revision);
        foreach ($workflow->jobs() as $job) {
            $bytes = SourceImageFixture::bytes(different: $job['message_id'] === 11);
            $workflow->accept(array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['sha256' => hash('sha256', $bytes), 'data' => base64_encode($bytes)]);
        }
        $originals = $this->storage->objects;
        $second = $this->db->select('SELECT storage_key FROM source_image_variants WHERE kind = ? ORDER BY id DESC', ['original'])[0]['storage_key'];
        $this->storage->objects[(string) $second] = 'changed file';
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
            self::fail('Digest mismatch must roll back uploads');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->db->table('media')->count());
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertCount(count($originals), $this->storage->objects);
        $this->storage->objects = $originals;
        $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        self::assertSame(2, $this->db->table('media')->count());
    }

    public function testSelectedArchivedVideoContractImportsThroughLibraryButNoRealGeneratorIsUsed(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \LogicException('Missing stream');
        }
        fwrite($stream, \App\Tests\Support\MediaFixtures::mp4());
        rewind($stream);
        $this->storage->put('source-videos/test/result', $stream);
        fclose($stream);
        $c = $this->app->container();
        $c->instance(\App\Integrations\Video\VideoProvider::class, new class () implements \App\Integrations\Video\VideoProvider {
            public function name(): string
            {
                return 'fixture';
            }
            public function generate(\App\Integrations\Video\VideoInput $input): \App\Integrations\Video\VideoResult
            {
                return new \App\Integrations\Video\VideoResult(false, 'source-videos/test/result');
            }
        });
        $workflow = $c->get(VideoWorkflow::class);
        $job = $workflow->request($ctx, $source, (string) $item['public_id'], $revision, VideoSettings::fromInput(['text_version' => $text]));
        $workflow->run($ctx, $source, (string) $item['public_id'], $job);
        $workflow->choose($ctx, $source, (string) $item['public_id'], $job);
        $service = $c->get(ContentDraftService::class);
        self::assertArrayHasKey($job, $service->videoChoices($ctx, $source, (string) $item['public_id']));
        $post = $service->create($ctx, $source, (string) $item['public_id'], $revision, $text, $job);
        self::assertCount(1, $post->mediaIds);
        self::assertSame('video', ($this->db->table('media')->first() ?? throw new \LogicException('Missing media'))['kind']);
        self::assertNull($c->get(ContentOriginGuard::class)->problem($post));
    }
    public function testProcessingDeletionAndDiscoveryRevisionDriftBlockExportedPosts(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->db->table('source_text_processings')->where('public_id', '=', $text)->delete();
        self::assertNotNull($c->get(ContentOriginGuard::class)->problem($post));
        $c->get(ContentDiscovery::class)->refresh($ctx);
        $candidate = $this->db->table('discovery_items')->first() ?? throw new \LogicException('Missing candidate');
        $gateway = $c->get(DiscoveryMaterialGateway::class);
        $id = $gateway->import($ctx, (string) $candidate['public_id']);
        $gateway->decide($ctx, $id, true);
        $material = $c->get(MaterialRepository::class)->item($ctx, null, $id);
        $rev = MaterialRepository::revision($material, []);
        $c->get(ContentProcessor::class)->process($ctx, null, $id, $rev, TextSettings::fromInput([]));
        $processed = $this->db->table('source_text_processings')->first() ?? throw new \LogicException('Missing text');
        $draft = $c->get(ContentDraftService::class)->create($ctx, null, $id, $rev, (string) $processed['public_id']);
        $this->db->table('discovery_items')->where('id', '=', $candidate['id'])->update(['title' => 'Changed upstream story']);
        self::assertNotNull($c->get(ContentOriginGuard::class)->problem($draft));
    }

    public function testRollbackCancelsLinkedQueueBeforeRemovingGuard(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $post = $this->app->container()->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $channel = $this->app->container()->get(\App\Domain\Channel\ChannelRepository::class)->all($ctx)[0];
        $this->service()->schedule($ctx, $post, $this->draft($post->baseText, [$channel]), $this->clock->now(), true);
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $this->db->table('publications')->update(['status' => 'sending']);
        try {
            $migration->down($this->db);
            self::fail('Rollback cannot detach an active publisher from its guard');
        } catch (\LogicException) {
            self::assertSame(1, $this->db->table('content_post_origins')->count());
        }
        $this->db->table('publications')->update(['status' => 'queued']);
        $migration->down($this->db);
        try {
            self::assertSame('cancelled', ($this->db->table('publications')->first() ?? throw new \LogicException('Missing publication'))['status']);
            self::assertSame('draft', $this->posts()->find($ctx, $post->publicId)?->status->value);
            self::assertSame(1, $this->drain()); // The already enqueued job observes cancelled publication and exits.
            self::assertCount(0, $this->fake->published);
        } finally {
            $migration->up($this->db);
        }
    }

    public function testIdenticalApprovalKeepsProcessingDraftAndPublishGuardCurrent(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $this->clock->advance(10);
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        self::assertSame($post->id, $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text)->id);
        self::assertSame(1, $this->db->table('source_text_processings')->count());
        self::assertSame(1, $this->db->table('content_post_origins')->count());
        self::assertNull($c->get(\App\Domain\Content\Publishing\ContentOriginGuard::class)->problem($post));
    }

    public function testLegacyDecisionHashMigrationPreservesProvenCurrentDraftAndProcessing(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text);
        $copies = [$this->service()->duplicate($ctx, $post), $this->service()->duplicate($ctx, $post)];
        $copyKeys = array_column($this->db->select('SELECT idempotency_key FROM content_post_origins WHERE post_id<>?', [$post->id]), 'idempotency_key');
        $decision = $c->get(MaterialRepository::class)->selection($ctx, $source, (int) $item['id']) ?? throw new \LogicException('Missing decision');
        unset($decision['revision_hash']);
        $oldHash = hash('sha256', json_encode($decision, JSON_THROW_ON_ERROR));
        $this->db->execute('UPDATE source_selection_decisions SET revision_hash=NULL WHERE workspace_id=? AND item_id=?', [$ctx->workspaceId, $item['id']]);
        $this->db->execute('UPDATE source_text_processings SET selection_hash=? WHERE workspace_id=? AND item_id=?', [$oldHash, $ctx->workspaceId, $item['id']]);
        $this->db->execute('UPDATE content_post_origins SET selection_hash=?, idempotency_key=? WHERE post_id=?', [$oldHash, hash('sha256', 'material:' . $item['id'] . ':' . $revision . ':' . $oldHash), $post->id]);
        $this->db->execute('UPDATE content_post_origins SET selection_hash=? WHERE post_id<>?', [$oldHash, $post->id]);
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_07_000032_review_fixes.php';
        $migration->up($this->db);
        $migration->up($this->db);
        self::assertNull($c->get(\App\Domain\Content\Publishing\ContentOriginGuard::class)->problem($post));
        self::assertSame($post->id, $c->get(ContentDraftService::class)->create($ctx, $source, (string) $item['public_id'], $revision, $text)->id);
        self::assertSame(1, $this->db->table('source_text_processings')->count());
        self::assertSame(3, $this->db->table('content_post_origins')->count());
        self::assertSame($copyKeys, array_column($this->db->select('SELECT idempotency_key FROM content_post_origins WHERE post_id<>?', [$post->id]), 'idempotency_key'));
        foreach ($copies as $copy) {
            self::assertNull($c->get(\App\Domain\Content\Publishing\ContentOriginGuard::class)->problem($copy));
        }
    }

    public function testTelegramDeletionInvalidatesExistingDraftWithoutRemovingHistory(): void
    {
        [$ctx, $source, $item, $revision, $text] = $this->fixture();
        $c = $this->app->container();
        $post = $c->get(ContentDraftService::class)->create($ctx, $source, $item['public_id'], $revision, $text);
        $c->get(\App\Domain\Source\SourceIngress::class)->accept(['version' => 1, 'source_id' => $source->publicId, 'connection_version' => 1, 'event_id' => hash('sha256', 'draft-deleted'), 'kind' => 'delete', 'payload' => ['peer_id' => '12345', 'message_ids' => [10], 'deleted_at' => '2026-10-06T10:00:00Z']]);
        self::assertNotNull($c->get(\App\Domain\Content\Publishing\ContentOriginGuard::class)->problem($post));
        self::assertSame(1, $this->db->table('content_post_origins')->count());
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(1, $this->db->table('source_messages')->count());
        $this->expectException(\App\Kernel\Exception\HttpException::class);
        $c->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
    }

}
