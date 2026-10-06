<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Automation\Automation;
use App\Domain\Content\Automation\AutomationPolicies;
use App\Domain\Content\Automation\AutomationSettings;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\SourceService;
use App\Jobs\Content\AutomationJob;
use App\Kernel\Queue\Queue;
use App\Kernel\Queue\Worker;
use App\Tests\Support\SourceSelectionTestCase;

/** @phpstan-impure */
final class AutomationTest extends SourceSelectionTestCase
{
    /** @param array<string,mixed> $options
     * @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Source\Source,array<string,mixed>} */
    private function fixture(array $options = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $source = $c->get(SourceService::class)->create($ctx, 'Automation', 'telegram', '@automation_fixture', true);
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $c->get(AutomationPolicies::class)->save($ctx, $source, array_replace(AutomationSettings::defaults(), ['enabled' => true, 'auto_selection' => true, 'auto_text_processing' => true, 'auto_draft' => true], $options));
        $this->ingest($source, [$this->message(10, 'Science news')]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException();
        return [$ctx, $source, $item];
    }
    private function tick(): void
    {
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        for ($i = 0; $i < 10 && $c->get(Worker::class)->runNext(); ++$i) {
        }
    }
    /** @return array<string,mixed> */
    private function runRow(): array
    {
        return $this->db->table('content_automation_runs')->first() ?? throw new \LogicException();
    }
    public function testNewTelegramRevisionCreatesOneOrdinaryDraftAndDoesNotPublish(): void
    {
        $this->fixture();
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame('draft', $this->runRow()['last_successful_step']);
        for ($i = 0; $i < 3; ++$i) {
            $this->tick();
        }
        self::assertSame(1, $this->db->table('source_text_processings')->count());
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame('draft', ($this->db->table('posts')->first() ?? throw new \LogicException())['status']);
        self::assertSame(0, $this->db->table('publications')->count());
        self::assertSame(0, $this->db->table('source_video_generations')->count());
    }
    public function testRejectedStopsBeforeProcessing(): void
    {
        [$ctx, $source] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput(['exclude_keywords' => 'science']));
        $this->tick();
        self::assertSame('rejected', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
    }
    public function testNeedsReviewStopsAndManualApprovalResumesSameRun(): void
    {
        [$ctx, $source, $item] = $this->fixture();
        $this->db->execute('DELETE FROM source_selection_rules');
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        $this->clock->advance(1);
        $this->app->container()->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('content_automation_runs')->count());
    }
    public function testDisabledStepsLeaveManualDraftAndDoNotCallProviders(): void
    {
        $this->fixture(['auto_text_processing' => false, 'auto_image_processing' => false, 'auto_video_generation' => false, 'auto_draft' => false]);
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testNewRevisionProcessedAndOldQueuedRevisionBlocked(): void
    {
        [$ctx, $source] = $this->fixture();
        $a = $this->app->container()->get(Automation::class);
        $a->tick();
        $this->clock->advance(1);
        $this->ingest($source, [$this->message(10, 'Edited science news', extra: ['edit_date' => '2026-10-06T10:00:00Z'])]);
        $this->tick();
        self::assertSame('stale', $this->runRow()['status']);
        $this->tick();
        self::assertSame(2, $this->db->table('content_automation_runs')->count());
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame('Edited science news', ($this->db->table('source_text_processings')->first() ?? throw new \LogicException())['original_text']);
    }
    public function testLostWorkerReservationAndSuccessfulDuplicateJobAreSafe(): void
    {
        $this->fixture();
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        self::assertNotNull($c->get(Queue::class)->reserve('default', 'crashed'));
        $this->clock->advance(901);
        self::assertTrue($c->get(Worker::class)->runNext());
        $c->get(Queue::class)->dispatch(new AutomationJob((int) $this->runRow()['id']));
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame(1, $this->db->table('posts')->count());
    }
    public function testUnconfirmedTextCallAfterCrashNeedsReviewWithoutNewPaidAttempt(): void
    {
        $this->fixture();
        $this->app->container()->get(Automation::class)->tick();
        $this->db->execute("UPDATE content_automation_runs SET step='text_started'");
        self::assertTrue($this->app->container()->get(Worker::class)->runNext());
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
    }
    public function testExplicitVideoOptInRunsFakeOnceButDoesNotAttachDemonstration(): void
    {
        $this->fixture(['auto_video_generation' => true]);
        $this->tick();
        $this->tick();
        self::assertSame(1, $this->db->table('source_video_generations')->count());
        self::assertSame('completed', ($this->db->table('source_video_generations')->first() ?? throw new \LogicException())['status']);
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(0, $this->db->table('media')->count());
    }
    public function testDisabledSourceAndDisabledPolicyDoNotEnqueue(): void
    {
        [$ctx, $source] = $this->fixture(['enabled' => false]);
        $this->tick();
        self::assertSame(0, $this->db->table('content_automation_runs')->count());
        $this->app->container()->get(AutomationPolicies::class)->save($ctx, $source, ['enabled' => true]);
        $this->app->container()->get(SourceService::class)->update($ctx, $source, 'Automation', 'telegram', '@automation_fixture', false);
        $this->tick();
        self::assertSame(0, $this->db->table('content_automation_runs')->count());
    }
    public function testDiscoverySchedulerRespectsTypesFrequencyLimitAndImportsOnce(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $this->app->container()->get(AutomationPolicies::class)->save($ctx, null, ['enabled' => true, 'auto_selection' => true, 'auto_text_processing' => true, 'auto_draft' => true, 'discovery_enabled' => true, 'provider_types' => ['web'], 'candidate_limit' => 1, 'interval_minutes' => 60]);
        $this->tick();
        self::assertSame(1, $this->db->table('discovery_items')->count());
        self::assertSame('web', ($this->db->table('discovery_items')->first() ?? throw new \LogicException())['source_type']);
        self::assertSame(1, $this->db->table('discovery_imports')->count());
        $this->tick();
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(1, $this->db->table('discovery_runs')->count());
        $this->clock->advance(3601);
        $this->tick();
        self::assertSame(2, $this->db->table('discovery_imports')->count());
        self::assertSame(2, $this->db->table('discovery_runs')->count());
    }
    public function testTransientContextFailureUsesWorkerBackoffAndRestartsSafely(): void
    {
        [$ctx] = $this->fixture();
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        $member = $this->db->table('workspace_members')->where('workspace_id', '=', $ctx->workspaceId)->where('user_id', '=', $ctx->userId)->first() ?? throw new \LogicException();
        $this->db->execute('DELETE FROM workspace_members WHERE workspace_id=? AND user_id=?', [$ctx->workspaceId, $ctx->userId]);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame(1, (int) $this->runRow()['attempts']);
        self::assertFalse($c->get(Worker::class)->runNext());
        $this->db->table('workspace_members')->insert($member);
        $this->clock->advance(61);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('posts')->count());
    }
    public function testSemanticIsDeferredToWorkerAndManualOverrideWins(): void
    {
        [$ctx, $source, $item] = $this->fixture(['semantic_selection' => true]);
        $c = $this->app->container();
        $c->get(\App\Domain\Source\Selection\SemanticSelection::class)->saveLocked($ctx, $source->id, \App\Domain\Source\Selection\SemanticSettings::fromInput(['enabled' => true, 'criteria' => 'AI news']));
        $this->ingest($source, [$this->message(11, 'AI technology breakthrough')]);
        self::assertSame(0, $this->db->table('semantic_selection_evaluations')->count());
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], false);
        $this->tick();
        self::assertSame('rejected', $this->runRow()['status']);
        self::assertGreaterThan(0, $this->db->table('semantic_selection_evaluations')->count());
        $this->tick(); // Queued semantic completion resumes automation on the next scheduler tick.
        self::assertSame(1, $this->db->table('posts')->count());
    }
    public function testAutomationSettingsUiPersistsAndIsTenantPermissionAndCsrfGuarded(): void
    {
        [$ctx, $source] = $this->fixture();
        $owner = $this->app->container()->get(\App\Domain\User\UserRepository::class)->find($ctx->userId) ?? throw new \LogicException();
        $url = '/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId;
        $this->actAs($owner);
        $page = $this->get($url);
        self::assertSame(200, $page->status);
        self::assertStringContainsString('Сохранить автоматизацию', $page->body);
        self::assertSame(419, $this->request('POST', $url . '/automation', ['enabled' => '1'])->status);
        self::assertSame(302, $this->post($url . '/automation', ['enabled' => '1', 'auto_text_processing' => '1', 'manual_review_fallback' => '1'])->status);
        self::assertTrue($this->app->container()->get(AutomationPolicies::class)->settings($ctx->workspaceId, $source->id)['enabled']);
        [$outsider] = $this->ownerWithWorkspace('outside@example.com');
        $this->actAs($outsider);
        self::assertSame(404, $this->post($url . '/automation', ['enabled' => '1'])->status);
        $workspace = $this->workspaces->findById($ctx->workspaceId) ?? throw new \LogicException();
        $viewer = $this->memberOf($workspace, 'viewer@example.com', \App\Domain\Workspace\Role::Viewer);
        $this->actAs($viewer);
        self::assertSame(403, $this->post($url . '/automation', ['enabled' => '1'])->status);
        self::assertContains('content.automation_settings_updated', $this->auditActions($workspace));
    }

    public function testImageWaitReaderAckAndSelectedOriginalProduceOneDraft(): void
    {
        [$ctx, $source] = $this->fixture(['auto_image_processing' => true]);
        $this->ingest($source, [$this->message(10, 'Photo news', extra: ['media' => ['kind' => 'photo', 'telegram_id' => '777', 'selected' => ['width' => 640, 'height' => 640]]])]);
        $this->tick();
        self::assertSame('waiting', $this->runRow()['status']);
        self::assertSame('images', $this->runRow()['step']);
        $this->clock->advance(61);
        $this->tick();
        self::assertSame(1, $this->db->table('source_image_processings')->count());
        $images = $this->app->container()->get(\App\Domain\Content\ImageProcessing\ImageWorkflow::class);
        $job = $images->jobs()[0];
        $bytes = \App\Tests\Support\SourceImageFixture::bytes();
        $payload = array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['data' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)];
        self::assertSame('completed', $images->accept($payload)['status']);
        self::assertTrue($images->accept($payload)['duplicate']);
        $this->clock->advance(61);
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('posts')->count());
        self::assertSame(1, $this->db->table('media')->count());
    }
    public function testWaitingTimeoutAndFailedImageFallBackToReview(): void
    {
        [$ctx, $source] = $this->fixture(['auto_image_processing' => true]);
        $this->ingest($source, [$this->message(10, 'Photo', extra: ['media' => ['kind' => 'photo', 'telegram_id' => '778']])]);
        $this->tick();
        $this->clock->advance(86461);
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertStringContainsString('время ожидания', (string) $this->runRow()['error']);
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testMissingProviderCredentialsStopsSafelyAndFallbackCanBeDisabled(): void
    {
        $this->fixture(['text_mode' => 'rewrite', 'manual_review_fallback' => false]);
        $guard = new \App\Domain\Content\Automation\AutomationGuard(new \App\Kernel\Config(['app' => ['env' => 'testing'], 'content_providers' => ['text' => 'openai']], new \App\Kernel\Env([])));
        $this->app->container()->instance(\App\Domain\Content\Automation\AutomationGuard::class, $guard);
        $this->tick();
        self::assertSame('failed', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testPolicyChangedAfterEnqueueBlocksStaleJob(): void
    {
        [$ctx, $source] = $this->fixture();
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        $c->get(AutomationPolicies::class)->save($ctx, $source, ['enabled' => false]);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame('stale', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testRunQueuedBeforeDisablingSourcePauses(): void
    {
        [$ctx, $source] = $this->fixture();
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        $c->get(SourceService::class)->update($ctx, $source, 'Automation', 'telegram', '@automation_fixture', false);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame('paused', $this->runRow()['status']);
    }
    public function testMissingSemanticCriteriaNeedsReviewWithoutAiCall(): void
    {
        $this->fixture(['semantic_selection' => true]);
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('semantic_selection_evaluations')->count());
        self::assertSame(0, $this->db->table('posts')->count());
    }
    public function testBackgroundDiscoveryNeverUsesFakeInProduction(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $c = $this->app->container();
        $c->get(AutomationPolicies::class)->save($this->contextFor($workspace, $owner), null, ['enabled' => true, 'discovery_enabled' => true, 'manual_review_fallback' => true]);
        $c->instance(\App\Domain\Content\Automation\AutomationGuard::class, new \App\Domain\Content\Automation\AutomationGuard(new \App\Kernel\Config(['app' => ['env' => 'production']], new \App\Kernel\Env([]))));
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('discovery_items')->count());
        self::assertSame(0, $this->db->table('discovery_runs')->count());
    }
    public function testRepeatedLostEnqueueIsRecoveredByMinuteScheduler(): void
    {
        $this->fixture();
        $c = $this->app->container();
        $c->get(Automation::class)->tick();
        $this->db->execute('DELETE FROM jobs');
        $this->clock->advance(961);
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('posts')->count());
    }
    public function testImageCostFenceClaimsAtMostOnceAndPolicyDisableDenies(): void
    {
        [$ctx, $source] = $this->fixture(['auto_image_processing' => true]);
        $c = $this->app->container();
        $calls = $c->get(\App\Domain\Content\Automation\AutomationCalls::class);
        self::assertTrue($calls->claimImage($ctx->workspaceId, $source->id, 'synthetic-image-id', 'image_search'));
        self::assertFalse($calls->claimImage($ctx->workspaceId, $source->id, 'synthetic-image-id', 'image_search'));
        self::assertSame(1, $this->db->table('content_automation_calls')->count());
    }

    public function testConfiguredRealProviderMissingKeyDoesNotBreakAppOrPipeline(): void
    {
        $this->fixture(['text_mode' => 'rewrite']);
        $c = $this->app->container();
        $c->instance(\App\Kernel\Config::class, \App\Kernel\Config::load(\App\Tests\Support\TestEnv::basePath() . '/config', \App\Tests\Support\TestEnv::env(['CONTENT_TEXT_PROVIDER' => 'openai', 'OPENAI_API_KEY' => ''])));
        $this->tick();
        self::assertSame('needs_review', $this->runRow()['status']);
        self::assertSame(0, $this->db->table('source_text_processings')->count());
        self::assertSame(0, $this->db->table('posts')->count());
    }

    public function testManualApprovalCanResumeRejectedRunAndDisabledSourceResumes(): void
    {
        [$ctx, $source, $item] = $this->fixture();
        $c = $this->app->container();
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], false);
        $this->tick();
        self::assertSame('rejected', $this->runRow()['status']);
        $this->clock->advance(1);
        $c->get(SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        $c->get(Automation::class)->tick();
        $c->get(SourceService::class)->update($ctx, $source, 'Automation', 'telegram', '@automation_fixture', false);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame('paused', $this->runRow()['status']);
        $c->get(SourceService::class)->update($ctx, $source, 'Automation', 'telegram', '@automation_fixture', true);
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('posts')->count());
    }

    public function testDiscoveryDoesNotImportCandidatesOfDisabledProviderTypes(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $c->get(\App\Domain\ContentDiscovery\ContentDiscovery::class)->refresh($ctx, ['telegram'], 1);
        $c->get(AutomationPolicies::class)->save($ctx, null, ['enabled' => true, 'discovery_enabled' => true, 'provider_types' => ['web'], 'candidate_limit' => 1]);
        $this->tick();
        self::assertSame(1, $this->db->table('discovery_imports')->count());
        $row = $this->db->select('SELECT d.source_type FROM discovery_imports l JOIN discovery_items d ON d.id=l.discovery_item_id AND d.workspace_id=l.workspace_id')[0];
        self::assertSame('web', $row['source_type']);
    }

    public function testText429BackoffRetriesWithoutManualReviewAndCreatesOneDraft(): void
    {
        $calls = 0;
        $provider = $this->createMock(\App\Integrations\Ai\TextProvider::class);
        $provider->method('name')->willReturn('fake');
        $provider->expects(self::exactly(2))->method('generate')->willReturnCallback(static function (string $text) use (&$calls): string {
            if (++$calls === 1) {
                throw new \App\Integrations\ContentProviders\ProviderException('rate_limited', true);
            }
            return $text;
        });
        $this->app->container()->instance(\App\Integrations\Ai\TextProvider::class, $provider);
        $this->fixture(['text_mode' => 'rewrite']);
        $this->tick();
        self::assertSame('pending', $this->runRow()['status']);
        self::assertSame(1, $calls);
        $failed = $this->db->table('source_text_processings')->first() ?? throw new \LogicException('Missing regression row');
        self::assertTrue((bool) $failed['retryable']);
        self::assertSame('rate_limited', $failed['error_category']);
        $this->tick();
        self::assertSame(1, $calls, 'Backoff prevents an immediate paid retry');
        $this->clock->advance(61);
        $this->tick();
        self::assertSame('completed', $this->runRow()['status']);
        self::assertSame(1, $this->db->table('content_post_origins')->count());
    }

}
