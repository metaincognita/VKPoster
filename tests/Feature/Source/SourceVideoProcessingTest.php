<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\VideoProcessing\VideoRepository;
use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Content\VideoProcessing\VideoWorkflow;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Source;
use App\Domain\Source\SourceService;
use App\Domain\Workspace\Role;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Storage\MediaStorage;
use App\Integrations\Video\VideoInput;
use App\Integrations\Video\VideoProvider;
use App\Integrations\Video\VideoResult;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\ArrayMediaStorage;
use App\Tests\Support\SourceImageFixture;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

final class SourceVideoProcessingTest extends SourceSelectionTestCase
{
    /** @return array{WorkspaceContext,Source,array<string,mixed>,string} */
    private function fixture(bool $approve = true): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Video source', 'telegram', '@video_fixture', true);
        $this->ingest($source, [$this->message(10, '<script>Original</script>')]);
        $item = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item');
        if ($approve) {
            $this->app->container()->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        }
        $this->actAs($owner);
        $revision = MaterialRepository::revision($item, $this->app->container()->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        return [$ctx, $source, $item, $revision];
    }
    public function testLifecycleHistorySelectionSnapshotAndUi(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/videos';
        self::assertStringContainsString('Пока нет заданий генерации', $this->get($url)->body);
        self::assertSame($url, $this->post($url, ['revision' => $revision, 'instruction' => '<script>Prompt</script>'])->header('Location'));
        $first = $this->db->table('source_video_generations')->first() ?? throw new \RuntimeException('Missing job');
        self::assertSame('pending', $first['status']);
        self::assertNull($first['result_json']);
        self::assertStringContainsString('Ожидает запуска', $this->get($url)->body);
        self::assertStringContainsString('&lt;script&gt;Prompt&lt;/script&gt;', $this->get($url)->body);
        $this->post($url . '/run', ['job' => $first['public_id']]);
        self::assertStringContainsString('Видеофайла нет', $this->get($url)->body);
        $this->post($url . '/run', ['job' => $first['public_id']]);
        $workflow = $this->app->container()->get(VideoWorkflow::class);
        $second = $workflow->request($ctx, $source, (string) $item['public_id'], $revision, VideoSettings::fromInput(['aspect_ratio' => '16:9', 'duration' => '30']));
        self::assertSame('completed', $workflow->run($ctx, $source, (string) $item['public_id'], $second));
        $this->post($url . '/select', ['job' => $first['public_id']]);
        $this->post($url . '/select', ['job' => $second]);
        $history = $this->app->container()->get(VideoRepository::class)->history($ctx, $source, (int) $item['id']);
        self::assertCount(2, $history);
        self::assertSame([2, 1], array_map('intval', array_column($history, 'settings_version')));
        self::assertSame([1, 0], array_map('intval', array_column($history, 'selected')));
        self::assertEquals(['demonstration' => true, 'storage_key' => null], json_decode((string) $history[0]['result_json'], true));
        self::assertSame('<script>Original</script>', ($this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item'))['text']);
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertStringContainsString('Итоговая версия', $this->get($url)->body);
        $this->post($url, ['revision' => $revision, 'duration' => '0', 'instruction' => 'KEEP INPUT']);
        $page = $this->get($url);
        self::assertStringContainsString('KEEP INPUT', $page->body);
        self::assertStringContainsString('aria-invalid="true"', $page->body);
        self::assertSame(2, $this->db->table('source_video_generations')->count());
        $audit = $this->db->select('SELECT action, meta_json FROM audit_log WHERE action LIKE ?', ['source.video_%']);
        self::assertNotEmpty($audit);
        self::assertStringNotContainsString('Prompt', json_encode($audit, JSON_THROW_ON_ERROR));
    }
    public function testProcessedTextAndSelectedImageAreImmutableBases(): void
    {
        [$ctx, $source, $item] = $this->fixture();
        $c = $this->app->container();
        $c->instance(MediaStorage::class, new ArrayMediaStorage());
        $this->ingest($source, [$this->message(10, 'Original photo', null, ['media' => ['kind' => 'photo', 'telegram_id' => '1010', 'selected' => ['type' => 'w', 'width' => 640, 'height' => 640, 'bytes' => 10000]]])]);
        $item = $c->get(MaterialRepository::class)->item($ctx, $source, (string) $item['public_id']);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $c->get(ContentProcessor::class)->process($ctx, $source, (string) $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'rewrite']));
        $text = $this->db->table('source_text_processings')->first() ?? throw new \RuntimeException('Missing text');
        $images = $c->get(ImageWorkflow::class);
        $images->request($ctx, $source, (string) $item['public_id'], $revision);
        $job = $images->jobs()[0];
        $bytes = SourceImageFixture::bytes();
        $images->accept(array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['data' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)]);
        $variant = $this->db->table('source_image_variants')->where('kind', '=', 'original')->first() ?? throw new \RuntimeException('Missing image');
        $capturing = new class () implements VideoProvider {
            public ?VideoInput $received = null;
            public function name(): string
            {
                return 'capture';
            }
            public function generate(VideoInput $input): VideoResult
            {
                $this->received = $input;
                return new VideoResult(true);
            }
        };
        $c->instance(VideoProvider::class, $capturing);
        $workflow = $c->get(VideoWorkflow::class);
        $id = $workflow->request($ctx, $source, (string) $item['public_id'], $revision, VideoSettings::fromInput(['text_version' => $text['public_id'], 'image_version' => $variant['public_id']]));
        self::assertSame('completed', $workflow->run($ctx, $source, (string) $item['public_id'], $id));
        self::assertSame($text['processed_text'], $capturing->received?->text);
        self::assertNotNull($capturing->received);
        self::assertNotNull($capturing->received->image);
        self::assertSame($variant['sha256'], $capturing->received->image['sha256']);
        self::assertSame('Original photo', ($this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item'))['text']);
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/videos';
        self::assertStringContainsString('Обработанный текст · версия 1', $this->get($url)->body);
        self::assertStringContainsString('Выбранное изображение · Telegram 10', $this->get($url)->body);
        $enhanced = $this->db->table('source_image_variants')->where('kind', '=', 'enhanced')->first() ?? throw new \RuntimeException('Missing enhanced');
        $images->choose($ctx, $source, (string) $item['public_id'], (string) $enhanced['public_id'], true);
        $row = $c->get(VideoRepository::class)->attempt($ctx, $source, (int) $item['id'], $id);
        self::assertFalse($workflow->current($ctx, $source, (string) $item['public_id'], $row));
        $this->expectException(HttpException::class);
        $workflow->choose($ctx, $source, (string) $item['public_id'], $id);
    }
    public function testOnlyApprovedCurrentItemsAndSafeFailures(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture(false);
        $c = $this->app->container();
        $workflow = $c->get(VideoWorkflow::class);
        $itemId = (string) $item['public_id'];
        foreach (['needs_review', 'rejected'] as $status) {
            if ($status === 'rejected') {
                $c->get(SelectionService::class)->decide($ctx, $source, $itemId, false);
            }
            try {
                $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([]));
                self::fail('Unapproved accepted');
            } catch (HttpException $e) {
                self::assertSame(409, $e->status);
            }
        }
        $c->get(SelectionService::class)->decide($ctx, $source, $itemId, true);
        try {
            $workflow->request($ctx, $source, $itemId, 'wrong', VideoSettings::fromInput([]));
            self::fail('Stale accepted');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $id = $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([]));
        $this->ingest($source, [$this->message(10, 'Edited')]);
        self::assertSame('failed', $workflow->run($ctx, $source, $itemId, $id));
        $c->instance(VideoProvider::class, new class () implements VideoProvider {
            public function name(): string
            {
                return 'broken';
            }
            public function generate(VideoInput $input): VideoResult
            {
                throw new \RuntimeException('API_KEY password PRIVATE');
            }
        });
        /** @var VideoWorkflow $workflow */
        $workflow = $c->make(VideoWorkflow::class);
        $item = $c->get(MaterialRepository::class)->item($ctx, $source, $itemId);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $id = $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([]));
        self::assertSame('failed', $workflow->run($ctx, $source, $itemId, $id));
        $row = $c->get(VideoRepository::class)->attempt($ctx, $source, (int) $item['id'], $id);
        self::assertStringNotContainsString('PRIVATE', (string) $row['error']);
        self::assertNull($row['result_json']);
    }
    public function testProcessingClaimRaceAndApprovalChangedDuringProvider(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $itemId = (string) $item['public_id'];
        $c = $this->app->container();
        $provider = new class () implements VideoProvider {
            public int $calls = 0;
            public \Closure $during;
            public function name(): string
            {
                return 'race';
            }
            public function generate(VideoInput $input): VideoResult
            {
                ++$this->calls;
                ($this->during)($input);
                return new VideoResult(true);
            }
        };
        $c->instance(VideoProvider::class, $provider);
        $workflow = $c->get(VideoWorkflow::class);
        $provider->during = function (VideoInput $input) use ($workflow, $ctx, $source, $itemId, $c): void {
            self::assertSame('processing', ($this->db->table('source_video_generations')->first() ?? throw new \RuntimeException('Missing job'))['status']);
            self::assertSame('processing', $workflow->run($ctx, $source, $itemId, $input->jobId));
            $c->get(SelectionService::class)->decide($ctx, $source, $itemId, false);
        };
        $id = $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([]));
        self::assertSame('failed', $workflow->run($ctx, $source, $itemId, $id));
        $workflow->run($ctx, $source, $itemId, $id);
        self::assertSame('failed', ($this->db->table('source_video_generations')->first() ?? throw new \RuntimeException('Missing job'))['status']);
        self::assertSame(1, $provider->calls);
        self::assertNull(($this->db->table('source_video_generations')->first() ?? throw new \RuntimeException('Missing job'))['result_json']);
    }
    public function testAuthCsrfWorkspaceItemAndJobIsolation(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $id = $this->app->container()->get(VideoWorkflow::class)->request($ctx, $source, (string) $item['public_id'], $revision, VideoSettings::fromInput([]));
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/videos';
        $this->useBrowser();
        self::assertSame('/login', $this->get($url)->header('Location'));
        $workspace = $this->db->table('workspaces')->where('id', '=', $ctx->workspaceId)->first() ?? throw new \RuntimeException('Missing workspace');
        // Workspace helper expects the domain object; obtain it through the standard fixture helper.
        [$editor, $otherWorkspace] = $this->ownerWithWorkspace('outsider@example.com', 'Other');
        $this->actAs($editor);
        self::assertSame(404, $this->get($url)->status);
        self::assertSame(404, $this->post($url . '/run', ['job' => $id])->status);
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \RuntimeException('Missing user');
        $this->actAs($owner);
        self::assertSame(419, $this->request('POST', $url . '/run', ['job' => $id])->status);
        self::assertSame(404, $this->post($url . '/run', ['job' => (string) new \Symfony\Component\Uid\Ulid()])->status);
        $this->db->execute('UPDATE workspace_members SET role = ? WHERE workspace_id = ? AND user_id = ?', [Role::Editor->value, $workspace['id'], $ctx->userId]);
        self::assertSame(403, $this->get($url)->status);
        self::assertSame(403, $this->post($url . '/run', ['job' => $id])->status);
    }
    public function testMissingOrStaleBasesAndProviderChangeAreRejected(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $c = $this->app->container();
        $workflow = $c->get(VideoWorkflow::class);
        $itemId = (string) $item['public_id'];
        $missing = (string) new \Symfony\Component\Uid\Ulid();
        foreach (['text_version', 'image_version'] as $field) {
            try {
                $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([$field => $missing]));
                self::fail('Missing basis accepted');
            } catch (HttpException $e) {
                self::assertContains($e->status, [404, 409]);
            }
        }
        $c->get(ContentProcessor::class)->process($ctx, $source, $itemId, $revision, TextSettings::fromInput([]));
        $text = $this->db->table('source_text_processings')->first() ?? throw new \RuntimeException('Missing text');
        $this->db->execute('UPDATE source_text_processings SET status = ?', ['failed']);
        try {
            $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput(['text_version' => $text['public_id']]));
            self::fail('Failed text accepted');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $id = $workflow->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput([]));
        try {
            $workflow->choose($ctx, $source, $itemId, $id);
            self::fail('Pending selected');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $c->instance(VideoProvider::class, new class () implements VideoProvider {
            public function name(): string
            {
                return 'replacement';
            }
            public function generate(VideoInput $input): VideoResult
            {
                throw new \LogicException('Must not run');
            }
        });
        /** @var VideoWorkflow $replacement */
        $replacement = $c->make(VideoWorkflow::class);
        self::assertSame('failed', $replacement->run($ctx, $source, $itemId, $id));
        $row = $c->get(VideoRepository::class)->attempt($ctx, $source, (int) $item['id'], $id);
        self::assertSame('Провайдер изменился. Создайте новое задание.', $row['error']);
    }
    public function testVideoMigrationRollbackAndReplay(): void
    {
        self::assertSame('app_test', $this->db->select('SELECT DATABASE() AS name')[0]['name']);
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000025_create_source_video_generations.php';
        $discovery = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $providers = require TestEnv::basePath() . '/database/migrations/2026_10_06_000029_add_content_provider_metadata.php';
        $providers->down($this->db);
        $discovery->down($this->db);
        $migration->down($this->db);
        try {
            self::assertSame([], $this->db->select('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['source_video_generations']));
        } finally {
            $migration->up($this->db);
            $discovery->up($this->db);
            $providers->up($this->db);
        }
        self::assertSame('pending', array_column($this->db->select('SHOW COLUMNS FROM source_video_generations'), 'Default', 'Field')['status']);
    }
}
