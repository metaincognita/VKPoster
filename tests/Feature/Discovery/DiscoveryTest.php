<?php

declare(strict_types=1);

namespace App\Tests\Feature\Discovery;

use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\ContentDiscovery\ContentDiscovery;
use App\Domain\ContentDiscovery\DiscoveryItem;
use App\Domain\ContentDiscovery\DiscoveryMaterialGateway;
use App\Domain\ContentDiscovery\DiscoveryRepository;
use App\Domain\Workspace\Role;
use App\Integrations\Discovery\DiscoveryProvider;
use App\Integrations\Discovery\DiscoveryProviders;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\TestEnv;
use App\Tests\Support\SourceSelectionTestCase;
use DateTimeImmutable;

final class DiscoveryTest extends SourceSelectionTestCase
{
    public function testDiscoveryDedupeClusterScoreAndImportToExistingProcessor(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $service = $c->get(ContentDiscovery::class);
        self::assertSame(4, $service->refresh($ctx));
        self::assertSame(0, $service->refresh($ctx));
        self::assertSame(4, $this->db->table('discovery_items')->count());
        self::assertSame(2, $this->db->table('discovery_clusters')->count());
        self::assertSame(0, $this->db->table('sources')->count());
        $clusters = $c->get(DiscoveryRepository::class)->clusters($ctx);
        self::assertCount(3, $clusters[0]['items']);
        self::assertSame(3, $clusters[0]['score']['components']['independent_sources']['count']);
        self::assertGreaterThan($clusters[1]['trend_score'], $clusters[0]['trend_score']);
        $item = $clusters[0]['items'][0];
        $gateway = $c->get(DiscoveryMaterialGateway::class);
        $id = $gateway->import($ctx, (string) $item['public_id']);
        self::assertSame($id, $gateway->import($ctx, (string) $item['public_id']));
        self::assertSame(1, $this->db->table('source_items')->count());
        self::assertSame(0, $this->db->table('source_messages')->count());
        $material = $c->get(MaterialRepository::class)->item($ctx, null, $id);
        self::assertNull($material['source_id']);
        $revision = MaterialRepository::revision($material, []);
        $processor = $c->get(ContentProcessor::class);
        try {
            $processor->process($ctx, null, $id, $revision, TextSettings::fromInput([]));
            self::fail('Unreviewed discovery processed');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        $gateway->decide($ctx, $id, true);
        self::assertSame('completed', $processor->process($ctx, null, $id, $revision, TextSettings::fromInput(['links' => 'remove', 'source_mentions' => 'remove'])));
        $history = $c->get(MaterialRepository::class)->history($ctx, null, (int) $material['id']);
        self::assertCount(1, $history);
        self::assertNull($history[0]['source_id']);
        self::assertStringNotContainsString('https://', (string) $history[0]['processed_text']);
        self::assertSame($material['text'], $c->get(MaterialRepository::class)->item($ctx, null, $id)['text']);
        $gateway->decide($ctx, $id, false);
        self::assertSame('rejected', ($c->get(MaterialRepository::class)->selection($ctx, null, (int) $material['id']) ?? throw new \RuntimeException('Missing decision'))['selection_status']);
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertSame(1, $this->db->table('discovery_imports')->count());
        self::assertSame(1, $this->db->table('discovery_clusters')->where('status', '=', 'imported')->count());
    }
    public function testIgnorePersistsAndInvalidStatusAndIdor(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $service = $c->get(ContentDiscovery::class);
        $service->refresh($ctx);
        $cluster = $c->get(DiscoveryRepository::class)->clusters($ctx)[0];
        $service->ignore($ctx, (string) $cluster['public_id']);
        self::assertSame(0, $service->refresh($ctx));
        self::assertCount(1, $c->get(DiscoveryRepository::class)->clusters($ctx, 'ignored'));
        try {
            $c->get(DiscoveryMaterialGateway::class)->import($ctx, (string) $cluster['items'][0]['public_id']);
            self::fail('Ignored imported');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $c->get(DiscoveryRepository::class)->clusters($ctx, 'invalid');
            self::fail('Invalid filter');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        [$other, $otherWs] = $this->ownerWithWorkspace('other@example.com');
        $foreign = $this->contextFor($otherWs, $other);
        self::assertSame([], $c->get(DiscoveryRepository::class)->clusters($foreign));
        try {
            $service->ignore($foreign, (string) $cluster['public_id']);
            self::fail('Foreign ignored');
        } catch (HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }
    public function testProvidersErrorsSafeAndUrlExternalTitleFingerprintDeduplication(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $provider = new class () implements DiscoveryProvider {
            /** @var list<DiscoveryItem> */ public array $items = [];
            public bool $fail = false;
            public function name(): string
            {
                return 'test';
            }
            public function sourceType(): string
            {
                return 'web';
            }
            public function discover(DateTimeImmutable $now): array
            {
                if ($this->fail) {
                    throw new \RuntimeException('PRIVATE_API_SECRET');
                } return $this->items;
            }
        };
        $c->instance(DiscoveryProviders::class, new DiscoveryProviders([$provider]));
        $service = $c->get(ContentDiscovery::class);
        $now = new DateTimeImmutable('2026-10-05T10:00:00Z');
        $make = static fn (string $url, ?string $external, string $title = 'Space telescope water discovery'): DiscoveryItem => new DiscoveryItem('test', 'web', 'desk', 'Desk', $url, $external, $title, 'Short excerpt', $now);
        $provider->items = [$make('https://example.com/a?utm_source=x', 'a')];
        self::assertSame(1, $service->refresh($ctx));
        $provider->items = [$make('https://example.com/a#fragment', 'b', 'Different headline')];
        self::assertSame(0, $service->refresh($ctx));
        $provider->items = [$make('https://example.com/new', 'a', 'Another headline')];
        self::assertSame(0, $service->refresh($ctx));
        $provider->items = [$make('https://example.com/new-title', null, 'Space telescope water discovery!')];
        self::assertSame(0, $service->refresh($ctx));
        self::assertSame(1, $this->db->table('discovery_items')->count());
        $provider->fail = true;
        self::assertSame(0, $service->refresh($ctx));
        $failed = $this->db->table('discovery_runs')->where('status', '=', 'failed')->first() ?? throw new \RuntimeException('Missing failure');
        self::assertStringNotContainsString('PRIVATE', (string) $failed['error']);
    }
    public function testUiCommandsAuthorizationCsrfEscapingAndContentFlow(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $url = $this->base($ws) . '/radar';
        self::assertSame('/login', $this->get($url)->header('Location'));
        self::assertSame('/login', $this->post($url . '/refresh')->header('Location'));
        $this->actAs($this->memberOf($ws, 'editor@example.com', Role::Editor));
        self::assertSame(403, $this->get($url)->status);
        self::assertSame(403, $this->post($url . '/refresh')->status);
        $this->actAs($this->createUser('outsider@example.com'));
        self::assertSame(404, $this->get($url)->status);
        $this->actAs($owner);
        self::assertStringContainsString('Пока нет тем', $this->get($url)->body);
        self::assertSame(419, $this->request('POST', $url . '/refresh')->status);
        self::assertSame($url, $this->post($url . '/refresh')->header('Location'));
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Почему такой score', $page->body);
        self::assertStringContainsString('Радар', $page->body);
        $item = $this->db->table('discovery_items')->first() ?? throw new \RuntimeException('Missing item');
        $this->db->execute('UPDATE discovery_clusters SET summary = ? WHERE id = ?', ['<script>Unsafe</script>', $item['cluster_id']]);
        self::assertStringContainsString('&lt;script&gt;Unsafe&lt;/script&gt;', $this->get($url)->body);
        $materialUrl = $this->post($url . '/items/' . $item['public_id'] . '/import')->header('Location');
        self::assertNotNull($materialUrl);
        $materialPage = $this->get($materialUrl);
        self::assertSame(200, $materialPage->status);
        self::assertStringContainsString('Материал из радара', $materialPage->body);
        self::assertStringNotContainsString('/images', $materialPage->body);
        $this->post($materialUrl . '/selection', ['decision' => 'approve']);
        $material = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing material');
        $this->post($materialUrl . '/process', ['revision' => MaterialRepository::revision($material, []), 'mode' => 'rewrite']);
        self::assertStringContainsString('Тестовая редакция:', $this->get($materialUrl)->body);
        self::assertSame(422, $this->post($materialUrl . '/selection', ['decision' => 'other'])->status);
        $this->post($materialUrl . '/process', ['revision' => 'invalid']);
        $this->post($materialUrl . '/process', ['mode' => 'custom', 'instruction' => 'KEEP INPUT', 'max_length' => '0']);
        self::assertStringContainsString('KEEP INPUT', $this->get($materialUrl)->body);
        self::assertStringContainsString('Открыть материал', $this->get($url . '?status=imported')->body);
        $this->post($materialUrl . '/selection', ['decision' => 'reject']);
        self::assertSame(1, $this->db->table('source_text_processings')->count());
        self::assertSame(0, $this->db->table('sources')->count());
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testForeignDiscoveryAndSourceMaterialsCannotCrossTheBoundary(): void
    {
        [$owner, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $owner);
        $c = $this->app->container();
        $c->get(ContentDiscovery::class)->refresh($ctx);
        $item = $c->get(DiscoveryRepository::class)->clusters($ctx)[0]['items'][0];
        $id = $c->get(DiscoveryMaterialGateway::class)->import($ctx, (string) $item['public_id']);
        [$other, $otherWs] = $this->ownerWithWorkspace('other@example.com');
        $this->actAs($other);
        $foreignBase = $this->base($otherWs) . '/radar';
        self::assertSame(404, $this->post($foreignBase . '/items/' . $item['public_id'] . '/import')->status);
        foreach (['', '/process', '/selection'] as $suffix) {
            $response = $suffix === '' ? $this->get($foreignBase . '/materials/' . $id) : $this->post($foreignBase . '/materials/' . $id . $suffix, ['decision' => 'approve']);
            self::assertSame(404, $response->status);
        }
        $this->actAs($owner);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Real Source', 'telegram', '@explicit_source', true);
        $this->ingest($source, [$this->message(10, 'Source-only content')]);
        $sourceItem = $this->db->table('source_items')->where('source_id', '=', $source->id)->first() ?? throw new \RuntimeException('Missing source item');
        self::assertSame(404, $this->get($this->base($ws) . '/radar/materials/' . $sourceItem['public_id'])->status);
        self::assertSame(404, $this->get($this->base($ws) . '/sources/' . $source->publicId . '/items/' . $id)->status);
        self::assertSame(419, $this->request('POST', $this->base($ws) . '/radar/materials/' . $id . '/selection', ['decision' => 'approve'])->status);
    }
    public function testMigrationRollbackReplayPreservesExistingSourceData(): void
    {
        self::assertSame('app_test', $this->db->select('SELECT DATABASE() AS name')[0]['name']);
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Existing source', 'telegram', '@original_source', true);
        $this->ingest($source, [$this->message(10, 'Existing Source text')]);
        $c->get(ContentDiscovery::class)->refresh($ctx);
        $item = $c->get(DiscoveryRepository::class)->clusters($ctx)[0]['items'][0];
        $id = $c->get(DiscoveryMaterialGateway::class)->import($ctx, (string) $item['public_id']);
        $c->get(DiscoveryMaterialGateway::class)->decide($ctx, $id, true);
        $material = $c->get(MaterialRepository::class)->item($ctx, null, $id);
        $c->get(ContentProcessor::class)->process($ctx, null, $id, MaterialRepository::revision($material, []), TextSettings::fromInput([]));
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $origins = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $origins->down($this->db);
        $migration->down($this->db);
        self::assertSame(1, $this->db->table('source_items')->count());
        self::assertSame('Existing Source text', ($this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing existing item'))['text']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertSame([], $this->db->select('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['discovery_items']));
        $migration->up($this->db);
        $origins->up($this->db);
        self::assertSame('YES', array_column($this->db->select('SHOW COLUMNS FROM source_items'), 'Null', 'Field')['source_id']);
    }
}
