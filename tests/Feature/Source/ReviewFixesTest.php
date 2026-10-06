<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Selection\SemanticSelection;
use App\Domain\Source\Selection\SemanticSettings;
use App\Domain\Source\SourceIngress;
use App\Domain\Source\SourceRepository;
use App\Domain\Source\SourceService;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\Selection\SemanticSelectionProvider;
use App\Integrations\Selection\SemanticSelectionResult;
use App\Kernel\Exception\HttpException;
use App\Tests\Support\SourceSelectionTestCase;

/** Regression scenarios for durable selection, connection identity and bounded safe retries. */
final class ReviewFixesTest extends SourceSelectionTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Source\Source} */
    private function fixture(): array
    {
        [$user, $ws] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($ws, $user);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Review fixture', 'telegram', '@review_channel', true);
        $this->app->container()->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        return [$ctx, $source];
    }
    /** @return array<string,mixed> */
    private function pending(): array
    {
        [$ctx, $source] = $this->fixture();
        $this->app->container()->get(SelectionService::class)->saveSemantic($ctx, $source, new SemanticSettings(true, 'AI', 70, 0.8, 'review'));
        $payload = ['peer_id' => '12345', 'grouped_id' => null, 'messages' => [$this->message(10, 'AI news')]];
        $this->app->container()->get(SourceIngress::class)->accept(['version' => 1, 'connection_version' => 1, 'source_id' => $source->publicId, 'event_id' => hash('sha256', 'review-event'), 'kind' => 'item', 'payload' => $payload]);
        return $this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing attempt');
    }
    public function testIngressCommitsWithoutNetworkAndWorkerCallsOutsideTransaction(): void
    {
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('review');
        $provider->expects(self::once())->method('evaluate')->willReturnCallback(function (): SemanticSelectionResult {
            self::assertFalse($this->db->pdo()->inTransaction());
            self::assertSame(1, $this->db->table('source_events')->count());
            self::assertSame('processing', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['status']);
            return new SemanticSelectionResult('approved', 95, 0.99, 'Fits');
        });
        $this->app->container()->instance(SemanticSelectionProvider::class, $provider);
        $row = $this->pending();
        self::assertSame('pending', $row['status']);
        self::assertStringNotContainsString('AI news', (string) $row['input_json']);
        $this->drainSemantic();
        self::assertSame('approved', $this->decision()['selection_status']);
    }
    public function testResponsePersistenceFailureCannotRepeatPaidOperation(): void
    {
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('review');
        $provider->expects(self::once())->method('evaluate')->willReturnCallback(function (): SemanticSelectionResult {
            // Simulate a storage outage after the remote response, without privileged MySQL triggers.
            $this->db->execute('ALTER TABLE semantic_selection_evaluations RENAME COLUMN decision TO review_unavailable_decision');
            return new SemanticSelectionResult('approved', 95, 0.99, 'Fits');
        });
        $this->app->container()->instance(SemanticSelectionProvider::class, $provider);
        $row = $this->pending();
        try {
            try {
                $this->drainSemantic();
                self::fail('Persistence must fail after provider response');
            } catch (\PDOException) {
                self::assertSame('processing', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['status']);
            }
        } finally {
            $this->db->execute('ALTER TABLE semantic_selection_evaluations RENAME COLUMN review_unavailable_decision TO decision');
        }
        $this->clock->advance(901);
        $c = $this->app->container();
        $c->get(SemanticSelection::class)->run((int) $row['id'], $c->get(SelectionService::class));
        self::assertSame('outcome_unknown', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['error_category']);
        self::assertSame('needs_review', $this->decision()['selection_status']);
    }
    public function testKnown429RetainsAttemptAndCanRetry(): void
    {
        $calls = 0;
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('review');
        $provider->expects(self::exactly(2))->method('evaluate')->willReturnCallback(static function () use (&$calls): SemanticSelectionResult {
            if (++$calls === 1) {
                throw new ProviderException('rate_limited', true);
            }
            return new SemanticSelectionResult('approved', 95, 0.99, 'Fits');
        });
        $this->app->container()->instance(SemanticSelectionProvider::class, $provider);
        $row = $this->pending();
        try {
            $this->drainSemantic();
            self::fail('Retryable failure must reach queue');
        } catch (ProviderException $e) {
            self::assertTrue($e->retryable);
        }
        self::assertSame('pending', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['status']);
        $this->clock->advance(60);
        $this->drainSemantic();
        self::assertSame((int) $row['id'], (int) ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['id']);
        self::assertSame('approved', $this->decision()['selection_status']);
    }
    public function testIdenticalManualDecisionHashAndEditRevisionBinding(): void
    {
        [$ctx, $source] = $this->fixture();
        $this->ingest($source, [$this->message(10)]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException('Missing regression row');
        $selection = $this->app->container()->get(SelectionService::class);
        $selection->decide($ctx, $source, $item['public_id'], true);
        $first = $this->decision();
        $this->clock->advance(10);
        $selection->decide($ctx, $source, $item['public_id'], true);
        self::assertSame(MaterialRepository::selectionHash($first), MaterialRepository::selectionHash($this->decision()));
        $selection->saveRules($ctx, $source, SelectionRules::fromInput(['exclude_keywords' => 'advertisement']));
        $this->ingest($source, [$this->message(10, 'advertisement', extra: ['edit_date' => '2026-10-05T11:00:00Z'])]);
        self::assertSame('automatic', $this->decision()['decision_mode']);
        self::assertSame('rejected', $this->decision()['selection_status']);
        self::assertNotSame($first['revision_hash'], $this->decision()['revision_hash']);
    }
    public function testPendingOldConnectionEventRejectedAfterRetarget(): void
    {
        [$ctx, $source] = $this->fixture();
        $updated = $this->app->container()->get(SourceRepository::class)->update($ctx, $source, $source->name, $source->type, 'other_channel', true);
        self::assertSame(2, $updated->connectionVersion);
        $third = $this->app->container()->get(SourceRepository::class)->update($ctx, $source, $source->name, $source->type, 'third_channel', true);
        self::assertSame(3, $third->connectionVersion, 'Stale controller snapshots cannot reuse a connection generation');
        try {
            $this->ingest($source, [$this->message(10)]);
            self::fail('Old connection must not enter new source');
        } catch (HttpException $e) {
            self::assertSame(422, $e->status);
        }
        self::assertSame(0, $this->db->table('source_items')->count());
        self::assertSame(0, $this->db->table('source_events')->count());
    }
    public function testAdditiveMigrationRollbackReplayAndDefaults(): void
    {
        self::assertSame('app_test', $this->db->select('SELECT DATABASE() AS name')[0]['name']);
        $migration = require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000032_review_fixes.php';
        $migration->down($this->db);
        self::assertNotContains('connection_version', array_column($this->db->select('SHOW COLUMNS FROM sources'), 'Field'));
        $migration->up($this->db);
        $migration->up($this->db); // Safe replay after an interrupted additive DDL deployment.
        $columns = array_column($this->db->select('SHOW COLUMNS FROM sources'), 'Default', 'Field');
        self::assertSame('1', $columns['connection_version']);
        self::assertContains('revision_hash', array_column($this->db->select('SHOW COLUMNS FROM source_selection_decisions'), 'Field'));
        self::assertContains('lease_generation', array_column($this->db->select('SHOW COLUMNS FROM source_video_generations'), 'Field'));
    }

    public function testManualRejectBeforeWorkerCancelsQueuedProviderCall(): void
    {
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('review');
        $provider->expects(self::never())->method('evaluate');
        $c = $this->app->container();
        $c->instance(SemanticSelectionProvider::class, $provider);
        $this->pending();
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException('Missing item');
        $source = $this->db->table('sources')->first() ?? throw new \LogicException('Missing Source');
        $this->db->execute("UPDATE source_selection_decisions SET decision_mode='manual', selection_status='rejected' WHERE item_id=? AND source_id=?", [$item['id'], $source['id']]);
        $this->drainSemantic();
        self::assertSame('rejected', $this->decision()['selection_status']);
        self::assertSame('blocked', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing attempt'))['status']);
    }

}
