<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\TextProcessor;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\VideoProcessing\VideoWorkflow;
use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Source\SourceService;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Selection\SemanticSelection;
use App\Domain\Source\Selection\SemanticPolicy;
use App\Domain\Source\Selection\SemanticSettings;
use App\Domain\Source\Selection\SelectionResult;
use App\Domain\Audit\AuditLog;
use App\Integrations\Video\AsyncVideoProvider;
use App\Integrations\Video\VideoProvider;
use App\Integrations\Video\VideoInput;
use App\Integrations\Video\VideoResult;
use App\Integrations\ContentProviders\ProviderHttp;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\Ai\OpenAiResponses;
use App\Integrations\Ai\OpenAiTextProvider;
use App\Integrations\Selection\OpenAiSemanticSelectionProvider;
use App\Support\Clock;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\MockHttpClient;
use App\Tests\Support\TestEnv;

/** @phpstan-impure */
final class RealProviderWorkflowTest extends SourceSelectionTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Source\Source,array<string,mixed>,string} */
    private function fixture(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Provider test', 'telegram', '@provider_fixture', true);
        $this->ingest($source, [$this->message(10, 'Original AI news')]);
        $item = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item');
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $this->actAs($owner);
        $revision = MaterialRepository::revision($item, $this->app->container()->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        return [$ctx, $source, $item, $revision];
    }
    public function testAsyncVideoPersistsRemoteIdPollsWithoutDuplicateGenerationAndSurvivesWorkflowRestart(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $provider = new class () implements AsyncVideoProvider {
            public int $starts = 0;
            public int $polls = 0;
            public function name(): string
            {
                return 'test_async';
            }
            public function generate(VideoInput $input): VideoResult
            {
                throw new \LogicException('Sync must not execute');
            }
            public function start(VideoInput $input): string
            {
                ++$this->starts;
                return 'durableid';
            }
            public function poll(string $providerJobId, string $localJobId): ?VideoResult
            {
                if ($providerJobId !== 'durableid') {
                    throw new \LogicException('Wrong remote ID');
                }
                ++$this->polls;
                if ($this->polls === 1) {
                    return null;
                }
                if ($this->polls === 2) {
                    throw new ProviderException('rate_limited', true);
                }
                return new VideoResult(false, 'source-videos/' . $localJobId . '/fixture');
            }
        };
        $c = $this->app->container();
        $c->instance(VideoProvider::class, $provider);
        $workflow = $c->get(VideoWorkflow::class);
        $job = $workflow->request($ctx, $source, $item['public_id'], $revision, VideoSettings::fromInput([]));
        $statuses = [];
        $statuses[] = $workflow->run($ctx, $source, $item['public_id'], $job);
        self::assertSame('durableid', ($this->db->table('source_video_generations')->first() ?? throw new \LogicException())['provider_job_id']);
        $statuses[] = $workflow->run($ctx, $source, $item['public_id'], $job);
        $statuses[] = $workflow->run($ctx, $source, $item['public_id'], $job);
        self::assertSame(['processing', 'processing', 'processing'], $statuses);
        $restart = new VideoWorkflow($this->db, $c->get(Clock::class), $c->get(MaterialRepository::class), $c->get(\App\Domain\Content\ImageProcessing\ImageRepository::class), $c->get(\App\Domain\Content\VideoProcessing\VideoRepository::class), $provider, $c->get(AuditLog::class));
        $completed = [$restart->run($ctx, $source, $item['public_id'], $job), $restart->run($ctx, $source, $item['public_id'], $job)];
        self::assertSame(['completed', 'completed'], $completed);
        self::assertSame(1, $provider->starts);
        self::assertSame(3, $provider->polls);
        $restart->choose($ctx, $source, $item['public_id'], $job);
        self::assertSame(1, (int) ($this->db->table('source_video_generations')->first() ?? throw new \LogicException())['selected']);
        self::assertSame($item, $this->db->table('source_items')->first());
        self::assertSame(0, $this->db->table('posts')->count());
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/videos';
        self::assertSame(200, $this->get($url)->status);
        self::assertStringContainsString('Итоговая версия', $this->get($url)->body);
    }
    public function testRealTextMetadataPersistsAndOriginalDoesNotChange(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $http = (new MockHttpClient())->expect('POST', 'https://api.openai.com/v1/responses', 200, (string) file_get_contents(TestEnv::basePath() . '/tests/Fixtures/ContentProviders/openai-text.json'));
        $provider = new OpenAiTextProvider(new OpenAiResponses(new ProviderHttp($http), 'test-only-key'));
        $c = $this->app->container();
        $workflow = new ContentProcessor($this->db, $c->get(Clock::class), $c->get(MaterialRepository::class), new TextProcessor($provider), $c->get(AuditLog::class));
        self::assertSame('completed', $workflow->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput(['mode' => 'rewrite'])));
        $attempt = $this->db->table('source_text_processings')->first() ?? throw new \LogicException();
        self::assertSame('openai', $attempt['provider']);
        self::assertSame(1300, json_decode($attempt['provider_metadata_json'], true)['total_tokens']);
        self::assertSame($item['text'], $attempt['original_text']);
        self::assertSame($item, $this->db->table('source_items')->first());
        $http->assertAllConsumed();
    }
    public function testSemanticMetadataPersistsAndDeterministicRejectionDoesNotCallApi(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $http = (new MockHttpClient())->expect('POST', 'https://api.openai.com/v1/responses', 200, (string) file_get_contents(TestEnv::basePath() . '/tests/Fixtures/ContentProviders/openai-semantic.json'));
        $c = $this->app->container();
        $semantic = new SemanticSelection($this->db, $c->get(Clock::class), new OpenAiSemanticSelectionProvider(new OpenAiResponses(new ProviderHttp($http), 'test-key')), new SemanticPolicy());
        $semantic->saveLocked($ctx, $source->id, SemanticSettings::fromInput(['enabled' => true, 'criteria' => 'AI news']));
        $messages = $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']);
        $row = $semantic->materialLocked($ctx->workspaceId, $source->id, $item, $messages, new SelectionResult('approved', 'Pass', 'none'));
        self::assertNotNull($row);
        self::assertSame(1150, json_decode($row['provider_metadata_json'], true)['total_tokens']);
        $row = $semantic->materialLocked($ctx->workspaceId, $source->id, $item, $messages, new SelectionResult('rejected', 'Exclude', 'exclude'));
        self::assertNotNull($row);
        self::assertSame('blocked', $row['status']);
        self::assertNull($row['provider_metadata_json']);
        $http->assertAllConsumed();
        self::assertCount(1, $http->requests);
    }
    public function testProviderMigrationRollbackReplayIsReversible(): void
    {
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_06_000029_add_content_provider_metadata.php';
        $migration->down($this->db);
        try {
            self::assertSame([], $this->db->select("SHOW COLUMNS FROM source_video_generations LIKE 'provider_job_id'"));
        } finally {
            $migration->up($this->db);
        }
        self::assertCount(1, $this->db->select("SHOW COLUMNS FROM source_video_generations LIKE 'provider_job_id'"));
    }
    public function testPollLeasePreventsConcurrentWorkAndCanBeReclaimed(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $provider = $this->createMock(AsyncVideoProvider::class);
        $provider->method('name')->willReturn('test_async');
        $provider->expects(self::once())->method('start')->willReturn('durableid');
        $provider->expects(self::once())->method('poll')->willReturn(null);
        $c = $this->app->container();
        $c->instance(VideoProvider::class, $provider);
        $workflow = $c->get(VideoWorkflow::class);
        $job = $workflow->request($ctx, $source, $item['public_id'], $revision, VideoSettings::fromInput([]));
        $workflow->run($ctx, $source, $item['public_id'], $job);
        $now = $c->get(Clock::class)->now();
        $this->db->execute('UPDATE source_video_generations SET poll_claimed_at = ? WHERE public_id = ? AND workspace_id = ?', [\App\Support\DbTime::format($now), $job, $ctx->workspaceId]);
        $statuses = [$workflow->run($ctx, $source, $item['public_id'], $job)];
        $this->db->execute('UPDATE source_video_generations SET poll_claimed_at = ? WHERE public_id = ? AND workspace_id = ?', [\App\Support\DbTime::format($now->modify('-121 seconds')), $job, $ctx->workspaceId]);
        $statuses[] = $workflow->run($ctx, $source, $item['public_id'], $job);
        self::assertSame(['processing', 'processing'], $statuses);
        self::assertNull(($this->db->table('source_video_generations')->first() ?? throw new \LogicException())['poll_claimed_at']);
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id'] . '/videos';
        self::assertStringContainsString('Проверить готовность', $this->get($url)->body);
    }
    public function testUnconfirmedStartAndExpiredOrStaleJobsAreNeverResubmitted(): void
    {
        [$ctx, $source, $item, $revision] = $this->fixture();
        $provider = $this->createMock(AsyncVideoProvider::class);
        $provider->method('name')->willReturn('test_async');
        $provider->expects(self::exactly(3))->method('start')->willReturn('durableid');
        $provider->expects(self::never())->method('poll');
        $c = $this->app->container();
        $c->instance(VideoProvider::class, $provider);
        $workflow = $c->get(VideoWorkflow::class);
        $statuses = [];
        foreach (['unconfirmed', 'expired', 'stale'] as $scenario) {
            $job = $workflow->request($ctx, $source, $item['public_id'], $revision, VideoSettings::fromInput([]));
            $workflow->run($ctx, $source, $item['public_id'], $job);
            if ($scenario === 'stale') {
                $this->ingest($source, [$this->message(10, 'Edited', extra: ['edit_date' => '2026-10-05T11:00:00Z'])]);
            } else {
                $date = \App\Support\DbTime::format($c->get(Clock::class)->now()->modify('-1000 seconds'));
                $this->db->execute('UPDATE source_video_generations SET provider_job_id = ?, started_at = ? WHERE public_id = ? AND workspace_id = ?', [$scenario === 'unconfirmed' ? null : 'durableid', $date, $job, $ctx->workspaceId]);
            }
            $statuses[] = $workflow->run($ctx, $source, $item['public_id'], $job);
        }
        self::assertSame(['failed', 'failed', 'failed'], $statuses);
    }

}
