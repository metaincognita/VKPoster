<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Source\Source;
use App\Domain\Source\SourceIngress;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceItemRepository;
use App\Kernel\Config;
use App\Kernel\Database\Migrator;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Tests\Support\TestEnv;
use App\Tests\Support\WorkspaceTestCase;

/** Real SQL and HTTP boundaries with synthetic Telegram snapshots; never uses a real reader account. */
final class SourceReaderTest extends WorkspaceTestCase
{
    private const SECRET = 'source-reader-test-secret-not-real-123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->container()->instance(Config::class, TestEnv::config(['SOURCES_READER_SECRET' => self::SECRET]));
    }

    /** @param array<string, mixed>|null $event */
    private function api(?array $event = null, string $secret = self::SECRET): Response
    {
        return $this->app->handle(Request::create(
            $event === null ? 'GET' : 'POST',
            $event === null ? '/internal/sources' : '/internal/source-events',
            headers: ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'],
            rawBody: $event === null ? '' : json_encode($event, JSON_THROW_ON_ERROR)
        ));
    }

    /** @return array<string, mixed> */
    private function message(int $id, ?string $group = null, string $text = 'Original', ?string $edit = null): array
    {
        return ['channel_id' => '12345', 'message_id' => $id, 'grouped_id' => $group, 'text' => $text,
            'entities' => [['_' => 'MessageEntityBold', 'offset' => 0, 'length' => 3]],
            'media' => $group === null ? null : ['kind' => 'photo', 'telegram_id' => (string) (1000 + $id), 'selected' => ['width' => 1280, 'height' => 720, 'bytes' => 1024]],
            'date' => '2026-10-05T10:00:00+00:00', 'edit_date' => $edit, 'content_hash' => hash('sha256', $text . $id . ($edit ?? ''))];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private function event(Source $source, array $messages): array
    {
        $payload = ['peer_id' => '12345', 'grouped_id' => $messages[0]['grouped_id'], 'messages' => $messages];
        return ['version' => 1, 'source_id' => $source->publicId, 'event_id' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 'kind' => 'item', 'payload' => $payload];
    }

    public function testSecretAndEnabledSourcesWithoutCredentials(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $service = $this->app->container()->get(SourceService::class);
        $context = $this->contextFor($workspace, $owner);
        $on = $service->create($context, 'Enabled', 'telegram', '@sample_channel', true);
        $off = $service->create($context, 'Disabled', 'telegram', '@other_channel');
        self::assertSame(403, $this->api(secret: 'wrong')->status);
        self::assertSame(['version' => 1, 'sources' => [['id' => $on->publicId, 'username' => 'sample_channel', 'connection_version' => 1]]], json_decode($this->api()->body, true));
        self::assertSame(409, $this->api($this->event($off, [$this->message(1)]))->status);
        self::assertSame(0, $this->db->table('source_events')->count());
        $this->app->container()->instance(Config::class, TestEnv::config());
        // A fresh controller is required because previously autowired instances retain their dependencies.
        $app = TestEnv::app();
        self::assertSame(404, $app->handle(Request::create('GET', '/internal/sources'))->status);
    }

    public function testCommitAckDedupEditsOutOfOrderAndUi(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Incoming', 'telegram', '@sample_channel', true);
        $event = $this->event($source, [$this->message(10)]);
        self::assertSame(200, $this->api($event)->status);
        self::assertSame(true, json_decode($this->api($event)->body, true)['duplicate']);
        $edit = $this->event($source, [$this->message(10, text: '<script>edited</script>', edit: '2026-10-05T11:00:00Z')]);
        self::assertSame(200, $this->api($edit)->status);
        $stale = $this->event($source, [$this->message(10, text: 'Stale')]);
        self::assertSame(200, $this->api($stale)->status);
        self::assertSame(1, $this->db->table('source_items')->count());
        self::assertSame(1, $this->db->table('source_messages')->count());
        self::assertSame('<script>edited</script>', $this->db->table('source_messages')->first()['text'] ?? null);
        self::assertSame('connected', $this->db->table('sources')->first()['status'] ?? null);
        $this->actAs($owner);
        $page = $this->get($this->base($workspace) . '/sources/' . $source->publicId);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Полученные материалы', $page->body);
        self::assertStringContainsString('&lt;script&gt;edited&lt;/script&gt;', $page->body);
        self::assertStringContainsString('Отредактирован', $page->body);
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertSame(0, $this->db->table('media')->count());
    }

    public function testAlbumIsOneItemAndMessagesAreMergedAcrossSnapshotsAndWorkspaces(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $context = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($context, 'Album', 'telegram', '@sample_channel', true);
        $first = $this->event($source, [$this->message(10, '999')]);
        self::assertSame(200, $this->api($first)->status);
        $second = $this->event($source, [$this->message(10, '999'), $this->message(11, '999', '')]);
        self::assertSame(200, $this->api($second)->status);
        self::assertSame(true, json_decode($this->api($second)->body, true)['duplicate']);
        self::assertSame(1, $this->db->table('source_items')->count());
        self::assertSame(2, $this->db->table('source_messages')->count());
        self::assertSame('album', $this->db->table('source_items')->first()['content_type'] ?? null);
        [$otherOwner, $other] = $this->ownerWithWorkspace('other@example.com');
        $repo = $this->app->container()->get(SourceItemRepository::class);
        self::assertSame([], $repo->recent($this->contextFor($other, $otherOwner), $source));
        self::assertCount(1, $repo->recent($context, $source));
    }

    public function testInvalidEventsAndIdentityCollisionsDoNotPartiallySave(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Incoming', 'telegram', '@sample_channel', true);
        $valid = $this->event($source, [$this->message(1)]);
        $invalid = $valid;
        $invalid['payload']['messages'][0]['media'] = ['api_hash' => 'never-store'];
        self::assertSame(422, $this->api($invalid)->status);
        self::assertSame(0, $this->db->table('source_events')->count());
        self::assertSame(200, $this->api($valid)->status);
        $invalid = $valid;
        $invalid['payload']['messages'][0]['text'] = 'collision';
        self::assertSame(409, $this->api($invalid)->status);
        $regroup = $this->event($source, [$this->message(1, '999')]);
        self::assertSame(409, $this->api($regroup)->status);
        self::assertSame(1, $this->db->table('source_events')->count());
        self::assertSame(1, $this->db->table('source_items')->count());
    }

    public function testDatabaseFailureHasNoAckOrPartialRowsAndRetrySucceeds(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Incoming', 'telegram', '@sample_channel', true);
        $event = $this->event($source, [$this->message(1)]);
        $this->db->execute('ALTER TABLE source_messages RENAME COLUMN media_json TO unavailable_media_json');
        try {
            $response = $this->api($event);
            self::assertSame(503, $response->status);
            self::assertSame(['ack' => false], json_decode($response->body, true));
            foreach (['source_events', 'source_items', 'source_messages'] as $table) {
                self::assertSame(0, $this->db->table($table)->count());
            }
        } finally {
            $this->db->execute('ALTER TABLE source_messages RENAME COLUMN unavailable_media_json TO media_json');
        }
        self::assertSame(200, $this->api($event)->status);
    }

    public function testIncomingMigrationRollbackAndReplay(): void
    {
        $path = TestEnv::basePath() . '/database/migrations/2026_10_05_000021_create_source_incoming.php';
        $migration = require $path;
        $selection = require TestEnv::basePath() . '/database/migrations/2026_10_05_000022_create_source_selection.php';
        $processing = require TestEnv::basePath() . '/database/migrations/2026_10_05_000023_create_source_text_processings.php';
        $images = require TestEnv::basePath() . '/database/migrations/2026_10_06_000024_create_source_image_processing.php';
        $videos = require TestEnv::basePath() . '/database/migrations/2026_10_06_000025_create_source_video_generations.php';
        $discovery = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $origins = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $providers = require TestEnv::basePath() . '/database/migrations/2026_10_06_000029_add_content_provider_metadata.php';
        $automation = require TestEnv::basePath() . '/database/migrations/2026_10_06_000030_create_content_automation.php';
        $automation->down($this->db);
        $providers->down($this->db);
        $origins->down($this->db);
        $discovery->down($this->db);
        $videos->down($this->db);
        $images->down($this->db);
        $processing->down($this->db);
        $selection->down($this->db);
        $migration->down($this->db);
        try {
            $migration->up($this->db);
            foreach (['source_events', 'source_items', 'source_messages'] as $table) {
                self::assertSame(0, $this->db->table($table)->count());
            }
        } finally {
            $selection->up($this->db);
            $processing->up($this->db);
            $images->up($this->db);
            $videos->up($this->db);
            $discovery->up($this->db);
            $origins->up($this->db);
            $providers->up($this->db);
            $automation->up($this->db);
            (require TestEnv::basePath() . '/database/migrations/2026_10_07_000032_review_fixes.php')->up($this->db);
            (new Migrator($this->db, TestEnv::basePath() . '/database/migrations'))->migrate();
        }
    }
}
