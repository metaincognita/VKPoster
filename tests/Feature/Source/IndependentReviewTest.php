<?php

declare(strict_types=1);

namespace App\Tests\Feature\Source;

use App\Domain\Content\Automation\AutomationPolicies;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\Publishing\ContentDraftService;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Selection\SemanticSelection;
use App\Domain\Source\Selection\SemanticSettings;
use App\Domain\Source\SourceIngress;
use App\Domain\Source\SourceRepository;
use App\Domain\Source\SourceService;
use App\Domain\Source\SourceType;
use App\Integrations\Selection\SemanticSelectionProvider;
use App\Integrations\Selection\SemanticSelectionResult;
use App\Kernel\Exception\HttpException;
use App\Kernel\Queue\Worker;
use App\Tests\Support\SourceSelectionTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Independent review regressions: queued selection, connection history and durable deletion. */
final class IndependentReviewTest extends SourceSelectionTestCase
{
    /** @return array{\App\Domain\Workspace\WorkspaceContext,\App\Domain\Source\Source} */
    private function fixture(): array
    {
        [$user, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $user);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Round two', 'telegram', '@round_two_a', true);
        $this->actAs($user);
        return [$ctx, $source];
    }
    /** @param list<array<string,mixed>> $messages */
    private function event(\App\Domain\Source\Source $source, array $messages, string $peer = '12345'): void
    {
        foreach ($messages as &$message) {
            $message['channel_id'] = $peer;
        }
        unset($message);
        $payload = ['peer_id' => $peer, 'grouped_id' => $messages[0]['grouped_id'], 'messages' => $messages];
        $this->app->container()->get(SourceIngress::class)->accept(['version' => 1, 'connection_version' => $source->connectionVersion, 'source_id' => $source->publicId, 'kind' => 'item', 'payload' => $payload, 'event_id' => hash('sha256', json_encode([$source->connectionVersion, $payload], JSON_THROW_ON_ERROR))]);
    }
    /** @return iterable<string,array{string,int,float}> */
    public static function decisions(): iterable
    {
        yield 'rejected' => ['rejected', 10, 0.99];
        yield 'review' => ['needs_review', 50, 0.3];
    }
    #[DataProvider('decisions')]
    public function testWorkerFinalizesDecisionBeforeAnyFurtherSchedulerTick(string $decision, int $score, float $confidence): void
    {
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('fixture');
        $provider->expects(self::once())->method('evaluate')->willReturn(new SemanticSelectionResult($decision, $score, $confidence, 'Fixture decision'));
        $c = $this->app->container();
        $c->instance(SemanticSelectionProvider::class, $provider);
        [$ctx, $source] = $this->fixture();
        $selection = $c->get(SelectionService::class);
        $selection->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $c->get(AutomationPolicies::class)->save($ctx, $source, ['enabled' => true, 'auto_selection' => true, 'semantic_selection' => true]);
        $c->get(SemanticSelection::class)->saveLocked($ctx, $source->id, new SemanticSettings(true, 'AI', 70, 0.8, 'review'));
        $this->event($source, [$this->message(10)]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException();
        $selection->evaluateLocked($ctx->workspaceId, $source->id, (int) $item['id'], true);
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame($decision, ($this->db->table('source_selection_decisions')->first() ?? throw new \LogicException('Missing regression row'))['selection_status']);
        $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
        try {
            $c->get(ContentProcessor::class)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
            self::fail('Processing must not bypass semantic decision');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        try {
            $c->get(ContentDraftService::class)->create($ctx, $source, $item['public_id'], $revision, '');
            self::fail('Draft must not bypass semantic decision');
        } catch (HttpException $e) {
            self::assertSame(409, $e->status);
        }
        self::assertSame(0, $this->db->table('posts')->count());
    }
    /** @return iterable<string,array{string}> */
    public static function disabled(): iterable
    {
        yield 'automation' => ['enabled'];
        yield 'automation semantic flag' => ['semantic_selection'];
        yield 'semantic policy' => ['semantic_policy'];
    }
    #[DataProvider('disabled')]
    public function testPendingSemanticDispatchIsCancelledWhenPermissionRevoked(string $flag): void
    {
        $provider = $this->createMock(SemanticSelectionProvider::class);
        $provider->method('name')->willReturn('fixture');
        $provider->expects(self::never())->method('evaluate');
        $c = $this->app->container();
        $c->instance(SemanticSelectionProvider::class, $provider);
        [$ctx, $source] = $this->fixture();
        $c->get(SelectionService::class)->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $policy = ['enabled' => true, 'auto_selection' => true, 'semantic_selection' => true];
        $c->get(AutomationPolicies::class)->save($ctx, $source, $policy);
        $c->get(SemanticSelection::class)->saveLocked($ctx, $source->id, new SemanticSettings(true, 'AI', 70, 0.8, 'review'));
        $this->event($source, [$this->message(10)]);
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException();
        $c->get(SelectionService::class)->evaluateLocked($ctx->workspaceId, $source->id, (int) $item['id'], true);
        if ($flag === 'semantic_policy') {
            $c->get(SemanticSelection::class)->saveLocked($ctx, $source->id, SemanticSettings::fromInput([]));
        } else {
            $policy[$flag] = false;
            $c->get(AutomationPolicies::class)->save($ctx, $source, $policy);
        }
        self::assertTrue($c->get(Worker::class)->runNext());
        self::assertSame('cancelled', ($this->db->table('semantic_selection_evaluations')->first() ?? throw new \LogicException('Missing regression row'))['status']);
        self::assertSame(0, $this->db->table('failed_jobs')->count());
        if ($flag !== 'semantic_policy') {
            $response = $this->get('/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId . '/items/' . $item['public_id']);
            self::assertSame(200, $response->status);
            self::assertStringContainsString('Оценка отменена', $response->body);
        }

    }
    public function testReconnectCreatesVisibleGenerationWithoutReactivatingOldItems(): void
    {
        [$ctx, $source] = $this->fixture();
        $c = $this->app->container();
        $repository = $c->get(SourceRepository::class);
        $first = null;
        foreach ([['round_two_a', '12345'], ['round_two_b', '99999'], ['round_two_a', '12345']] as $i => [$username, $peer]) {
            if ($i > 0) {
                $source = $repository->update($ctx, $source, 'Round two', SourceType::Telegram, $username, true);
            }
            $this->event($source, [$this->message(10)], $peer);
            $visible = $c->get(\App\Domain\Source\SourceItemRepository::class)->recent($ctx, $source);
            self::assertCount(1, $visible);
            self::assertSame($i + 1, (int) $visible[0]['connection_version']);
            $c->get(SelectionService::class)->decide($ctx, $source, $visible[0]['public_id'], true);
            $item = $c->get(MaterialRepository::class)->item($ctx, $source, $visible[0]['public_id']);
            $revision = MaterialRepository::revision($item, $c->get(MaterialRepository::class)->messages($ctx, $source, (int) $item['id']));
            self::assertSame('completed', $c->get(ContentProcessor::class)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([])));
            if ($i === 0) {
                $first = $item['public_id'];
            }
        }
        self::assertSame(3, $this->db->table('source_items')->count());
        self::assertSame(3, $this->db->table('source_messages')->count());
        try {
            (require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000033_independent_review.php')->down($this->db);
            self::fail('Rollback cannot merge historical generations');
        } catch (\LogicException $e) {
            self::assertStringContainsString('rollback would merge identities', $e->getMessage());
        }

        self::assertSame(200, $this->get('/w/' . $ctx->workspacePublicId . '/sources/' . $source->publicId)->status);
        $this->expectException(HttpException::class);
        $c->get(MaterialRepository::class)->item($ctx, $source, (string) $first);
    }
    /** @return iterable<string,array{bool,bool}> */
    public static function deletions(): iterable
    {
        yield 'text' => [false, false];
        yield 'photo' => [true, false];
        yield 'album member' => [true, true];
    }
    #[DataProvider('deletions')]
    public function testDeletionPreservesHistoryAndInvalidatesManualApproval(bool $photo, bool $album): void
    {
        [$ctx, $source] = $this->fixture();
        $messages = [$this->message(10, 'Caption one', $album ? '777' : null, ['media' => $photo ? ['kind' => 'photo'] : null])];
        if ($album) {
            $messages[] = $this->message(11, 'Caption two', '777');
        }
        $this->event($source, $messages);
        $c = $this->app->container();
        $item = $this->db->table('source_items')->first() ?? throw new \LogicException();
        $c->get(SelectionService::class)->decide($ctx, $source, $item['public_id'], true);
        $repo = $c->get(MaterialRepository::class);
        $revision = MaterialRepository::revision($item, $repo->messages($ctx, $source, (int) $item['id']));
        $event = ['version' => 1, 'source_id' => $source->publicId, 'connection_version' => 1, 'kind' => 'delete', 'event_id' => hash('sha256', 'deletion'), 'payload' => ['peer_id' => '12345', 'message_ids' => [10], 'deleted_at' => '2026-10-06T10:00:00Z']];
        self::assertTrue($c->get(SourceIngress::class)->accept($event));
        self::assertFalse($c->get(SourceIngress::class)->accept($event));
        $current = $repo->item($ctx, $source, $item['public_id']);
        self::assertNotSame($revision, MaterialRepository::revision($current, $repo->messages($ctx, $source, (int) $item['id'])));
        self::assertNotSame('approved', ($repo->selection($ctx, $source, (int) $item['id']) ?? throw new \LogicException('Missing decision'))['selection_status']);
        self::assertSame($album ? 'Caption two' : '', $current['text']);
        self::assertSame($album ? 'stored' : 'deleted', $current['status']);
        self::assertSame(count($messages), $this->db->table('source_messages')->count());
        self::assertSame('Caption one', ($this->db->table('source_messages')->first() ?? throw new \LogicException('Missing regression row'))['text']);
        self::assertSame(2, $this->db->table('source_events')->count());
        $this->expectException(HttpException::class);
        $c->get(ContentProcessor::class)->process($ctx, $source, $item['public_id'], $revision, TextSettings::fromInput([]));
    }
    public function testMigration33RollbackReplay(): void
    {
        $migration = require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000033_independent_review.php';
        $migration->down($this->db);
        $migration->up($this->db);
        $migration->up($this->db);
        self::assertCount(4, $this->db->select("SHOW INDEX FROM source_messages WHERE Key_name='uq_source_message'"));
    }
    public function testTombstoneBeforeDelayedSnapshotCannotResurrectMaterial(): void
    {
        [$ctx, $source] = $this->fixture();
        $c = $this->app->container();
        $c->get(SourceIngress::class)->accept(['version' => 1, 'source_id' => $source->publicId, 'connection_version' => 1, 'kind' => 'delete', 'event_id' => hash('sha256', 'early-delete'), 'payload' => ['peer_id' => '12345', 'message_ids' => [10], 'deleted_at' => '2026-10-06T10:00:00Z']]);
        $this->event($source, [$this->message(10)]);
        self::assertSame('deleted', ($this->db->table('source_items')->first() ?? throw new \LogicException('Missing regression row'))['status']);
        self::assertSame('rejected', ($this->db->table('source_selection_decisions')->first() ?? throw new \LogicException('Missing regression row'))['selection_status']);
        self::assertSame(2, $this->db->table('source_events')->count());
    }

}
