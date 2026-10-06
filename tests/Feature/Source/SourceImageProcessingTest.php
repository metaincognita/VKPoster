<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Source;
use App\Domain\Source\SourceService;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Images\FakeImageSearchProvider;
use App\Integrations\Images\ImageCandidate;
use App\Integrations\Images\ImageEnhancementProvider;
use App\Integrations\Images\ImageSearchProvider;
use App\Integrations\Storage\MediaStorage;
use App\Kernel\Config;
use App\Kernel\Exception\HttpException;
use App\Kernel\Http\Request;
use App\Kernel\Http\Response;
use App\Tests\Support\ArrayMediaStorage;
use App\Tests\Support\SourceImageFixture;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

/** Real SQL, HTTP, pixel inspection, private previews, multiple photos and conservative manual selection; no live providers. */
final class SourceImageProcessingTest extends SourceSelectionTestCase
{
    private const SECRET = 'image-contract-test-only-not-a-real-secret';
    private ArrayMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new ArrayMediaStorage();
        $this->app->container()->instance(MediaStorage::class, $this->storage);
        $this->app->container()->instance(Config::class, TestEnv::config(['SOURCES_READER_SECRET' => self::SECRET]));
        $this->app->container()->instance(ImageSearchProvider::class, new FakeImageSearchProvider([
            new ImageCandidate(SourceImageFixture::bytes(1600), 'https://example.com/original.jpg'),
            new ImageCandidate(SourceImageFixture::bytes(1600, different: true), 'https://example.com/different.jpg'),
        ]));
    }

    /** @return array{Source,WorkspaceContext,array<string,mixed>,string,string} */
    private function fixture(bool $album = false): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Photos', 'telegram', '@sample_channel', true);
        $messages = [];
        foreach ($album ? [10, 11] : [10] as $id) {
            $messages[] = $this->message($id, '<script>Caption</script>', $album ? '777' : null, ['media' => ['kind' => 'photo', 'telegram_id' => (string) (1000 + $id), 'selected' => ['type' => 'w', 'width' => 640, 'height' => 640, 'bytes' => 10000]]]);
        }
        $this->ingest($source, $messages);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $repo = $this->app->container()->get(MaterialRepository::class);
        $revision = MaterialRepository::revision($item, $repo->messages($ctx, $source, (int) $item['id']));
        $url = $this->base($workspace) . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/images';
        $this->actAs($owner);
        return [$source, $ctx, $item, $revision, $url];
    }

    /** @param array<string,mixed>|null $payload */
    private function api(?array $payload = null, string $secret = self::SECRET): Response
    {
        return $this->app->handle(Request::create($payload === null ? 'GET' : 'POST', $payload === null ? '/internal/source-image-jobs' : '/internal/source-image-results', headers: ['Authorization' => 'Bearer ' . $secret, 'Content-Type' => 'application/json', 'Accept' => 'application/json'], rawBody: $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR)));
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function payload(array $job, int $size = 640): array
    {
        $bytes = SourceImageFixture::bytes($size);
        return array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['sha256' => hash('sha256', $bytes), 'data' => base64_encode($bytes)];
    }

    public function testAlbumOriginalArchivesChoicesPrivatePreviewsAndIdempotentAck(): void
    {
        [$source, $ctx, $item, $revision, $url] = $this->fixture(true);
        self::assertSame(200, $this->get($url)->status);
        self::assertStringContainsString('Для обработки сначала примите', $this->get($url)->body);
        $this->post($url, ['revision' => $revision]);
        self::assertSame(0, $this->db->table('source_image_processings')->count());
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        self::assertSame($url, $this->post($url, ['revision' => $revision])->header('Location'));
        $this->post($url, ['revision' => $revision]);
        self::assertSame(2, $this->db->table('source_image_processings')->count());
        $jobs = json_decode($this->api()->body, true)['jobs'];
        self::assertCount(2, $jobs);
        foreach ($jobs as $job) {
            $response = $this->api($this->payload($job));
            self::assertSame(200, $response->status, $response->body);
            self::assertSame('completed', json_decode($response->body, true)['status']);
            self::assertTrue(json_decode($this->api($this->payload($job))->body, true)['duplicate']);
        }
        self::assertSame(8, $this->db->table('source_image_variants')->count());
        self::assertCount(0, json_decode($this->api()->body, true)['jobs']);
        $run = $this->db->table('source_image_processings')->first();
        self::assertNotNull($run);
        $variants = $this->db->table('source_image_variants')->where('processing_id', '=', $run['id'])->orderBy('id')->get();
        self::assertSame('original', $variants[0]['kind']);
        self::assertSame($variants[0]['public_id'], $run['selected_variant']);
        self::assertSame(SourceImageFixture::bytes(), stream_get_contents($this->storage->read($variants[0]['storage_key'])));
        self::assertSame('verified', $variants[1]['verification']);
        self::assertSame('mismatch', $variants[2]['verification']);
        self::assertSame('https://example.com/original.jpg', $variants[1]['source_url']);
        self::assertNotSame($variants[0]['storage_key'], $variants[3]['storage_key']);
        self::assertSame($variants[0]['sha256'], $variants[3]['sha256']);
        $this->post($url . '/select', ['variant' => $variants[2]['public_id']]);
        self::assertSame($variants[0]['public_id'], ($this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing regression row'))['selected_variant'] ?? null);
        foreach ([$variants[1], $variants[3], $variants[0]] as $variant) {
            $this->post($url . '/select', ['variant' => $variant['public_id']]);
            self::assertSame($variant['public_id'], ($this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing regression row'))['selected_variant'] ?? null);
        }
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertMatchesRegularExpression('/<title>[^<]*Обработка изображений[^<]*<\/title>/u', $page->body);
        foreach (['Исходное изображение', 'Найденная версия', 'Enhanced-версия', 'Итоговое выбранное изображение', '640×640', 'SHA-256', 'https://example.com/original.jpg', 'Проверка совпадения'] as $label) {
            self::assertStringContainsString($label, $page->body);
        }
        self::assertStringNotContainsString('source-images/', $page->body);
        $preview = $this->get($url . '/' . $variants[0]['public_id'] . '/preview');
        self::assertSame(200, $preview->status);
        self::assertSame('image/webp', $preview->header('Content-Type'));
        self::assertIsResource($preview->stream);
        fclose($preview->stream);
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], false);
        $this->post($url . '/select', ['variant' => $variants[1]['public_id']]);
        self::assertSame($variants[0]['public_id'], ($this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing regression row'))['selected_variant'] ?? null);
        self::assertStringContainsString('Эта попытка недоступна для выбора', $this->get($url)->body);
        self::assertSame(0, $this->db->table('posts')->count());
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertSame($item, $this->db->table('source_items')->first());
    }

    public function testAuthenticationPermissionsCsrfAndWorkspaceIsolation(): void
    {
        [$source, $ctx, $item, $revision, $url] = $this->fixture();
        self::assertSame(403, $this->api(secret: 'wrong')->status);
        self::assertSame(419, $this->request('POST', $url)->status);
        $this->useBrowser();
        self::assertSame('/login', $this->get($url)->header('Location'));
        self::assertSame('/login', $this->post($url)->header('Location'));
        $workspace = $this->workspaces->findById($ctx->workspaceId);
        self::assertNotNull($workspace);
        $this->actAs($this->memberOf($workspace, 'image-editor@example.com', \App\Domain\Workspace\Role::Editor));
        self::assertSame(403, $this->get($url)->status);
        self::assertSame(403, $this->post($url)->status);
        $other = $this->createUser('outsider@example.com');
        $this->actAs($other);
        self::assertSame(404, $this->get($url)->status);
        self::assertSame(404, $this->post($url, ['revision' => $revision])->status);
        self::assertSame(0, $this->db->table('source_image_processings')->count());
    }

    public function testStaleEditDisabledSourceFailureAndDigestIdentityGuards(): void
    {
        [$source, $ctx, $item, $revision, $url] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $job = $workflow->jobs()[0];
        self::assertSame(422, $this->api(array_replace($this->payload($job), ['sha256' => 'wrong']))->status);
        self::assertSame(422, $this->api(array_replace($this->payload($job), ['photo_id' => '999']))->status);
        self::assertSame(422, $this->api(array_replace($this->payload($job), ['peer_id' => ['invalid']]))->status);
        self::assertSame(422, $this->api($this->payload($job, 320))->status);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
        $this->db->execute('UPDATE sources SET enabled = 0 WHERE id = ?', [$source->id]);
        self::assertSame([], $workflow->jobs());
        $this->db->execute('UPDATE sources SET enabled = 1 WHERE id = ?', [$source->id]);
        $this->ingest($source, [$this->message(10, 'Edited', extra: ['edit_date' => '2026-10-06T11:00:00Z'])]);
        self::assertSame('stale', json_decode($this->api($this->payload($job))->body, true)['status']);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
        self::assertSame([], $workflow->jobs());
    }

    public function testConcurrentRejectionDoesNotAcceptGeneratedVersions(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $selection = $this->app->container()->get(SelectionService::class);
        $selection->decide($ctx, $source, $item['public_id'], true);
        $fake = $this->createMock(ImageEnhancementProvider::class);
        $fake->method('name')->willReturn('fake');
        $fake->method('enhance')->willReturnCallback(function () use ($selection, $ctx, $source, $item): string {
            $selection->decide($ctx, $source, $item['public_id'], false);
            return SourceImageFixture::bytes();
        });
        $this->app->container()->instance(ImageEnhancementProvider::class, $fake);
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $job = $workflow->jobs()[0];
        self::assertSame('stale', json_decode($this->api($this->payload($job))->body, true)['status']);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
    }

    public function testReaderFailureAndUnavailableProvidersPreserveOriginalWithoutLeakingErrors(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $search = $this->createMock(ImageSearchProvider::class);
        $search->method('search')->willThrowException(new \RuntimeException('private-api-key-content'));
        $enhancer = $this->createMock(ImageEnhancementProvider::class);
        $enhancer->method('enhance')->willThrowException(new \RuntimeException('private-password-content'));
        $this->app->container()->instance(ImageSearchProvider::class, $search);
        $this->app->container()->instance(ImageEnhancementProvider::class, $enhancer);
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $job = $workflow->jobs()[0];
        $failure = array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['error' => 'private-password-do-not-save'];
        self::assertSame('failed', json_decode($this->api($failure)->body, true)['status']);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $job = $workflow->jobs()[0];
        self::assertSame('completed', json_decode($this->api($this->payload($job))->body, true)['status']);
        self::assertSame(1, $this->db->table('source_image_variants')->count());
        foreach ($this->db->table('source_image_processings')->get() as $row) {
            self::assertStringNotContainsString('private', (string) $row['error']);
        }
        self::assertCount(2, $this->storage->objects);
    }

    public function testStorageFailureHasNoAckAndRetryArchivesExactlyOnce(): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $storage = $this->createMock(MediaStorage::class);
        $fail = new \ArrayObject(['enabled' => true]);
        $storage->method('put')->willReturnCallback(function (string $key, $stream) use ($fail): void {
            if ($fail['enabled'] === true) {
                throw new \RuntimeException('private-storage-password');
            }
            $this->storage->put($key, $stream);
        });
        $storage->method('delete')->willReturnCallback(fn (string $key) => $this->storage->delete($key));
        $this->app->container()->instance(MediaStorage::class, $storage);
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $job = $workflow->jobs()[0];
        self::assertSame(503, $this->api($this->payload($job))->status);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
        self::assertSame('queued', ($this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing regression row'))['status'] ?? null);
        $fail['enabled'] = false;
        self::assertSame(200, $this->api($this->payload($job))->status);
        self::assertSame(4, $this->db->table('source_image_variants')->count());
        self::assertCount(8, $this->storage->objects);
    }

    public function testApprovedTextWithoutMediaAndOutdatedRequestsAreSafe(): void
    {
        [$source, $ctx] = $this->fixture();
        $this->ingest($source, [$this->message(20, 'Text only')]);
        $item = $this->db->table('source_items')->where('item_key', '=', 'message:20')->first();
        self::assertNotNull($item);
        $selection = $this->app->container()->get(SelectionService::class);
        $selection->decide($ctx, $source, $item['public_id'], true);
        $repo = $this->app->container()->get(MaterialRepository::class);
        $revision = MaterialRepository::revision($item, $repo->messages($ctx, $source, (int) $item['id']));
        $workflow = $this->app->container()->get(ImageWorkflow::class);
        self::assertSame(0, $workflow->request($ctx, $source, $item['public_id'], $revision));
        self::assertSame(0, $this->db->table('source_image_processings')->count());
        $this->expectException(HttpException::class);
        $workflow->request($ctx, $source, $item['public_id'], 'previous revision');
    }

    public function testMigrationReplay(): void
    {
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000024_create_source_image_processing.php';
        $providers = require TestEnv::basePath() . '/database/migrations/2026_10_06_000029_add_content_provider_metadata.php';
        $automation = require TestEnv::basePath() . '/database/migrations/2026_10_06_000030_create_content_automation.php';
        $automation->down($this->db);
        $providers->down($this->db);
        $migration->down($this->db);
        $migration->up($this->db);
        $providers->up($this->db);
        $automation->up($this->db);
        self::assertSame(0, $this->db->table('source_image_variants')->count());
    }
    public function testUnavailableOldSourceCannotStarveAvailablePhotoJobs(): void
    {
        [$old, $ctx, $item, $revision] = $this->fixture();
        $c = $this->app->container();
        $workflow = $c->get(ImageWorkflow::class);
        $c->get(SelectionService::class)->decide($ctx, $old, (string) $item['public_id'], true);
        $workflow->request($ctx, $old, (string) $item['public_id'], $revision);
        $base = $this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing regression row');
        unset($base['id']);
        for ($i = 0; $i < 100; ++$i) {
            $base['public_id'] = (string) new \Symfony\Component\Uid\Ulid();
            $this->db->table('source_image_processings')->insert($base);
        }
        $available = $c->get(SourceService::class)->create($ctx, 'Available', 'telegram', '@available_channel', true);
        $this->ingest($available, [$this->message(20, extra: ['media' => ['kind' => 'photo', 'telegram_id' => '2020']])]);
        $other = $this->db->table('source_items')->where('source_id', '=', $available->id)->first() ?? throw new \LogicException('Missing regression row');
        $c->get(SelectionService::class)->decide($ctx, $available, (string) $other['public_id'], true);
        $current = MaterialRepository::revision($other, $c->get(MaterialRepository::class)->messages($ctx, $available, (int) $other['id']));
        $workflow->request($ctx, $available, (string) $other['public_id'], $current);
        $jobs = $workflow->jobs([$available->publicId]);
        self::assertCount(1, $jobs);
        self::assertSame($available->publicId, $jobs[0]['source_id']);
        self::assertContains($available->publicId, array_column($workflow->jobs(), 'source_id'));
        self::assertSame([], $workflow->jobs([]));
    }

    /** @return iterable<string,array{string,bool}> */
    public static function imageFailures(): iterable
    {
        yield 'search 429' => ['rate_limited', false];
        yield 'search confirmed 503' => ['unavailable', false];
        yield 'enhancement 429' => ['rate_limited', true];
        yield 'enhancement confirmed 503' => ['unavailable', true];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('imageFailures')]
    public function testConfirmedImageFailuresRetryWithoutRepeatingSuccessfulOperations(string $category, bool $enhancement): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $c = $this->app->container();
        $search = $this->createMock(ImageSearchProvider::class);
        $search->method('name')->willReturn('fixture_search');
        if ($enhancement) {
            $search->expects(self::once())->method('search')->willReturn([new ImageCandidate(SourceImageFixture::bytes(1600), 'https://example.com/original.jpg')]);
        } else {
            $search->expects(self::exactly(2))->method('search')->willReturnCallback(static function () use ($category, &$calls): array {
                $calls = ($calls ?? 0) + 1;
                if ($calls === 1) {
                    throw new \App\Integrations\ContentProviders\ProviderException($category, true);
                }
                return [];
            });
        }
        $enhancer = $this->createMock(ImageEnhancementProvider::class);
        $enhancer->method('name')->willReturn('fixture_enhancement');
        if ($enhancement) {
            $enhancer->expects(self::exactly(2))->method('enhance')->willReturnCallback(static function () use ($category, &$enhanceCalls): ?string {
                $enhanceCalls = ($enhanceCalls ?? 0) + 1;
                if ($enhanceCalls === 1) {
                    throw new \App\Integrations\ContentProviders\ProviderException($category, true);
                }
                return null;
            });
        } else {
            $enhancer->expects(self::once())->method('enhance')->willReturn(null);
        }
        $c->instance(ImageSearchProvider::class, $search);
        $c->instance(ImageEnhancementProvider::class, $enhancer);
        $c->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $workflow = $c->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $payload = $this->payload($workflow->jobs()[0]);
        self::assertSame(503, $this->api($payload)->status);
        self::assertSame('retryable', $this->db->select("SELECT status FROM content_automation_calls WHERE operation=?", [$enhancement ? 'image_enhancement' : 'image_search'])[0]['status']);
        self::assertSame(503, $this->api($payload)->status);
        if ($enhancement) {
            $cached = json_decode((string) $this->db->select("SELECT result_json FROM content_automation_calls WHERE operation='image_search'")[0]['result_json'], true, 32, JSON_THROW_ON_ERROR);
            self::assertCount(1, $cached);
            self::assertArrayHasKey($cached[0]['storage_key'], $this->storage->objects);
            self::assertArrayHasKey($cached[0]['preview_key'], $this->storage->objects);
        }
        $this->clock->advance(61);
        self::assertSame(200, $this->api($payload)->status);
        self::assertSame('completed', ($this->db->table('source_image_processings')->first() ?? throw new \LogicException('Missing processing'))['status']);
        self::assertSame(200, $this->api($payload)->status);
    }
    /** @return iterable<string,array{bool}> */
    public static function uncertainOperations(): iterable
    {
        yield 'search' => [false];
        yield 'enhancement' => [true];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('uncertainOperations')]
    public function testUncertainImageOutcomeCannotRepeatPaidOperation(bool $enhancement): void
    {
        [$source, $ctx, $item, $revision] = $this->fixture();
        $c = $this->app->container();
        $provider = $this->createMock($enhancement ? ImageEnhancementProvider::class : ImageSearchProvider::class);
        $provider->method('name')->willReturn('fixture');
        $provider->expects(self::once())->method($enhancement ? 'enhance' : 'search')->willThrowException(new \App\Integrations\ContentProviders\ProviderException('outcome_unknown'));
        $c->instance($enhancement ? ImageEnhancementProvider::class : ImageSearchProvider::class, $provider);
        $c->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $workflow = $c->get(ImageWorkflow::class);
        $workflow->request($ctx, $source, $item['public_id'], $revision);
        $payload = $this->payload($workflow->jobs()[0]);
        $response = $this->api($payload);
        self::assertSame(200, $response->status);
        $retry = $this->api($payload);
        self::assertSame(200, $retry->status);
        self::assertSame('uncertain', $this->db->select('SELECT status FROM content_automation_calls WHERE operation=?', [$enhancement ? 'image_enhancement' : 'image_search'])[0]['status']);
    }

}
