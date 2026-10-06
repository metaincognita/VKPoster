<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Operations\ContentMaintenance;
use App\Domain\Content\Operations\ContentStatus;
use App\Domain\Content\Operations\StorageRegistry;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\SourceService;
use App\Integrations\Ai\TextProvider;
use App\Integrations\Storage\MediaStorage;
use App\Kernel\Config;
use App\Tests\Support\ArrayMediaStorage;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

/** Real SQL recovery, safe retention, guarded late completion and authenticated heartbeat.
 * @phpstan-impure */
final class ContentHardeningTest extends SourceSelectionTestCase
{
    private ArrayMediaStorage $storage;
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->execute('DELETE FROM content_storage_objects');
        $this->storage = new ArrayMediaStorage();
        $this->app->container()->instance(MediaStorage::class, $this->storage);
        $this->app->container()->instance(Config::class, TestEnv::config(['CONTENT_RETENTION_ENABLED' => 'true', 'SOURCES_READER_SECRET' => 'test-reader-only-not-production-secret']));
    }
    public function testOrphanCleanupDryRunApplyAndIdempotency(): void
    {
        $c = $this->app->container();
        $key = 'source-images/1/orphan/original';
        $c->get(StorageRegistry::class)->track($key);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'fixture');
        rewind($stream);
        $this->storage->put($key, $stream);
        fclose($stream);
        $this->clock->advance(31 * 86400);
        $maintenance = $c->get(ContentMaintenance::class);
        self::assertSame(1, $maintenance->prune()['objects']);
        self::assertTrue($this->storage->exists($key));
        self::assertSame(1, $maintenance->prune(true)['deleted']);
        self::assertFalse($this->storage->exists($key));
        self::assertSame(0, $maintenance->prune(true)['deleted']);
        self::assertSame(['text' => 0, 'video' => 0, 'images' => 0], $maintenance->recover());
    }
    public function testCleanupDisabledByDefaultEvenWithApply(): void
    {
        $c = $this->app->container();
        $c->instance(Config::class, TestEnv::config());
        $c->get(StorageRegistry::class)->track('source-images/1/missing/original');
        $this->clock->advance(31 * 86400);
        self::assertSame(0, $c->get(ContentMaintenance::class)->prune(true)['deleted']);
        $this->expectException(\InvalidArgumentException::class);
        $c->get(StorageRegistry::class)->track('ws/1/private');
    }
    public function testCrashRecoveryPreventsLateResultOverwrite(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Hardening', 'telegram', '@hardening_fixture', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $this->ingest($source, [$this->message(10, 'Fixture')]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $provider = $this->createMock(TextProvider::class);
        $provider->method('name')->willReturn('fake');
        $provider->method('generate')->willReturnCallback(function (): string {
            $this->clock->advance(3601);
            self::assertSame(1, $this->app->container()->get(ContentMaintenance::class)->recover()['text']);
            return 'late result';
        });
        $c->instance(TextProvider::class, $provider);
        self::assertSame('failed', $c->get(ContentProcessor::class)->process($ctx, $source, (string) $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'rewrite'])));
        $row = $this->db->table('source_text_processings')->first();
        self::assertNotNull($row);
        self::assertNull($row['processed_text']);
        self::assertSame(0, $c->get(ContentMaintenance::class)->recover()['text']);
    }
    public function testReaderHeartbeatAuthenticatedAndStatusContainsNoSecretsOrContent(): void
    {
        $c = $this->app->container();
        self::assertSame('unknown', $c->get(ContentStatus::class)->report()['reader']['state']);
        self::assertSame(403, $this->request('POST', '/internal/reader-heartbeat', [], ['Authorization' => 'Bearer wrong'])->status);
        self::assertSame(200, $this->request('POST', '/internal/reader-heartbeat', [], ['Authorization' => 'Bearer test-reader-only-not-production-secret'])->status);
        $report = $c->get(ContentStatus::class)->report();
        self::assertSame('ok', $report['reader']['state']);
        self::assertStringNotContainsString('secret', json_encode($report, JSON_THROW_ON_ERROR));
        $this->clock->advance(190);
        self::assertSame('stale', $c->get(ContentStatus::class)->report()['reader']['state']);
    }
    public function testMigrationRollbackDoesNotDeleteObjects(): void
    {
        $key = 'source-images/1/rollback/original';
        $this->app->container()->get(StorageRegistry::class)->track($key);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'archive');
        rewind($stream);
        $this->storage->put($key, $stream);
        fclose($stream);
        $migration = require dirname(__DIR__, 3) . '/database/migrations/2026_10_06_000031_create_content_storage_objects.php';
        $migration->down($this->db);
        $migration->up($this->db);
        self::assertSame(0, $this->db->table('content_storage_objects')->count());
        self::assertTrue($this->storage->exists($key));
    }
    public function testReferencedOriginalAndChosenVariantAreNeverPruned(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Images', 'telegram', '@retention_fixture', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $this->ingest($source, [$this->message(10, 'Photo', extra: ['media' => ['kind' => 'photo', 'telegram_id' => '1001']])]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $images = $c->get(\App\Domain\Content\ImageProcessing\ImageWorkflow::class);
        $images->request($ctx, $source, (string) $item['public_id'], $revision);
        $job = $images->jobs()[0];
        $bytes = \App\Tests\Support\SourceImageFixture::bytes(1600);
        $payload = array_intersect_key($job, array_flip(['job_id','peer_id','message_id','photo_id'])) + ['data' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
        self::assertSame('completed', $images->accept($payload)['status']);
        $variants = $this->db->table('source_image_variants')->get();
        self::assertNotEmpty($variants);
        $this->clock->advance(31 * 86400);
        self::assertSame(0, $c->get(ContentMaintenance::class)->prune(true)['deleted']);
        foreach ($variants as $variant) {
            self::assertTrue($this->storage->exists((string) $variant['storage_key']));
            self::assertTrue($this->storage->exists((string) $variant['preview_key']));
        }
        self::assertTrue($images->accept($payload)['duplicate']);
        self::assertCount(count($variants), $this->db->table('source_image_variants')->get());
    }
    public function testTemporaryCleanupPreservesFreshForeignAndSymlinkFiles(): void
    {
        $old = tempnam(sys_get_temp_dir(), 'source-image-');
        $fresh = tempnam(sys_get_temp_dir(), 'source-preview-');
        $foreign = tempnam(sys_get_temp_dir(), 'foreign-');
        self::assertIsString($old);
        self::assertIsString($fresh);
        self::assertIsString($foreign);
        $link = sys_get_temp_dir() . '/source-image-link-' . bin2hex(random_bytes(4));
        symlink($foreign, $link);
        touch($old, $this->clock->now()->getTimestamp() - 31 * 86400);
        touch($foreign, $this->clock->now()->getTimestamp() - 31 * 86400);
        try {
            $m = $this->app->container()->get(ContentMaintenance::class);
            self::assertGreaterThanOrEqual(1, $m->prune()['temporary']);
            self::assertFileExists($old);
            $m->prune(true);
            self::assertFileDoesNotExist($old);
            self::assertFileExists($fresh);
            self::assertFileExists($foreign);
            self::assertTrue(is_link($link));
            $m->tick();
        } finally {
            foreach ([$old,$fresh,$foreign,$link] as $p) {
                if (file_exists($p) || is_link($p)) {
                    unlink($p);
                }
            }
        }
    }
    public function testAsyncRemoteVideoIdSurvivesRecoveryAndPendingLimitIsEnforced(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Video', 'telegram', '@recovery_fixture', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $this->ingest($source, [$this->message(10, 'Video')]);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        $videos = $c->get(\App\Domain\Content\VideoProcessing\VideoWorkflow::class);
        for ($i = 0; $i < 3; ++$i) {
            $videos->request($ctx, $source, (string) $item['public_id'], $revision, \App\Domain\Content\VideoProcessing\VideoSettings::fromInput([]));
        }
        $first = $this->db->table('source_video_generations')->first();
        self::assertNotNull($first);
        $id = $first['id'];
        $this->db->execute("UPDATE source_video_generations SET status='processing'");
        $this->db->execute('UPDATE source_video_generations SET provider_job_id=? WHERE id=?', ['durableremoteid', $id]);
        $this->clock->advance(3601);
        self::assertSame(2, $c->get(ContentMaintenance::class)->recover()['video']);
        $row = $this->db->table('source_video_generations')->where('id', '=', $id)->first();
        self::assertNotNull($row);
        self::assertSame('processing', $row['status']);
        self::assertSame('durableremoteid', $row['provider_job_id']);
        for ($i = 0; $i < 2; ++$i) {
            $videos->request($ctx, $source, (string) $item['public_id'], $revision, \App\Domain\Content\VideoProcessing\VideoSettings::fromInput([]));
        }
        try {
            $videos->request($ctx, $source, (string) $item['public_id'], $revision, \App\Domain\Content\VideoProcessing\VideoSettings::fromInput([]));
            self::fail('Must limit');
        } catch (\App\Kernel\Exception\HttpException $e) {
            self::assertSame(429, $e->status);
        }
        $this->clock->advance(86401);
        self::assertSame(2, $c->get(ContentMaintenance::class)->recover()['video']);
        $key = 'source-videos/retention/video';
        $c->get(StorageRegistry::class)->track($key);
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'video');
        rewind($stream);
        $this->storage->put($key, $stream);
        fclose($stream);
        $this->db->execute('UPDATE source_video_generations SET status=?,result_json=? WHERE id=?', ['completed', json_encode(['storage_key' => $key], JSON_THROW_ON_ERROR), $id]);
        $this->clock->advance(31 * 86400);
        self::assertSame(0, $c->get(ContentMaintenance::class)->prune(true)['deleted']);
        self::assertTrue($this->storage->exists($key));
    }
    public function testOperationalCommandsUseSafeExistingConsole(): void
    {
        $out = new \App\Kernel\Console\Output();
        $c = $this->app->container();
        self::assertSame(0, $c->get(\App\Kernel\Console\Command\ContentStatusCommand::class)->run([], $out));
        self::assertSame(0, $c->get(\App\Kernel\Console\Command\ContentMaintainCommand::class)->run([], $out));
        self::assertStringContainsString('recovery', $out->contents());
        self::assertStringContainsString('queue', $out->contents());
    }

    public function testWorkerConcurrencySlotContentionDefersWithoutProviderCall(): void
    {
        [$owner,$workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Busy', 'telegram', '@busy_fixture', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $c->get(\App\Domain\Content\Automation\AutomationPolicies::class)->save($ctx, $source, ['enabled' => true,'auto_selection' => true,'auto_text_processing' => true,'auto_draft' => true]);
        $this->ingest($source, [$this->message(10, 'Busy fixture')]);
        $a = $c->get(\App\Domain\Content\Automation\Automation::class);
        $a->tick();
        $run = $this->db->table('content_automation_runs')->first();
        self::assertNotNull($run);
        $other = TestEnv::connection();
        foreach ([0,1] as $slot) {
            self::assertSame(1, (int) $other->select('SELECT GET_LOCK(?,0) AS acquired', ['content-worker-slot:'.$slot])[0]['acquired']);
        }
        try {
            $a->run((int) $run['id']);
            self::assertSame(0, $this->db->table('source_text_processings')->count());
        } finally {
            foreach ([0,1] as $slot) {
                $other->select('SELECT RELEASE_LOCK(?)', ['content-worker-slot:'.$slot]);
            }
        }
        $a->run((int) $run['id']);
        self::assertSame(1, $this->db->table('posts')->count());
        $a->run((int) $run['id']);
        self::assertCount(1, $this->db->table('posts')->get());
    }

}
