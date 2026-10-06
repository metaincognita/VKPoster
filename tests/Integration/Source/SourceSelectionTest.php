<?php

declare(strict_types=1);

namespace App\Tests\Integration\Source;

use App\Domain\Source\SourceService;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\Selection\SelectionService;
use App\Tests\Support\SourceSelectionTestCase;
use App\Tests\Support\TestEnv;

final class SourceSelectionTest extends SourceSelectionTestCase
{
    public function testRulesSnapshotsReevaluationAndManualOverrideAfterEdit(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $service = $this->app->container()->get(SelectionService::class);
        $message = $this->message(10);
        self::assertTrue($this->ingest($source, [$message]));
        self::assertSame('needs_review', $this->decision()['selection_status']);
        self::assertFalse($this->ingest($source, [$message]));
        self::assertSame(1, $this->db->table('source_selection_decisions')->count());
        $service->saveRules($ctx, $source, SelectionRules::fromInput(['include_keywords' => 'наука']));
        self::assertSame('approved', $this->decision()['selection_status']);
        self::assertSame(1, $this->decision()['rules_version']);
        self::assertSame(['наука'], json_decode($this->decision()['rules_snapshot_json'], true)['include_keywords']);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        $service->decide($ctx, $source, $item['public_id'], false);
        $manual = $this->decision();
        $service->saveRules($ctx, $source, SelectionRules::fromInput([]));
        $this->ingest($source, [$this->message(10, 'Новая наука', extra: ['edit_date' => '2026-10-05T11:00:00Z'])]);
        self::assertSame($manual, $this->decision());
        $stored = $this->db->table('source_items')->first();
        self::assertNotNull($stored);
        self::assertSame('stored', $stored['status']);
        $service->decide($ctx, $source, $item['public_id'], true);
        self::assertSame('approved', $this->decision()['selection_status']);
        self::assertSame('manual', $this->decision()['decision_mode']);
        self::assertSame($owner->id, $this->decision()['decided_by']);
        self::assertContains('source.rules_updated', $this->auditActions($workspace));
        self::assertContains('source.item_rejected', $this->auditActions($workspace));
        self::assertContains('source.item_approved', $this->auditActions($workspace));
        self::assertSame(0, $this->db->table('posts')->count());
    }

    public function testAlbumEvaluatedAsOneItemAndAutomaticEditsReevaluate(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $service = $this->app->container()->get(SelectionService::class);
        $service->saveRules($ctx, $source, SelectionRules::fromInput(['include_keywords' => 'second', 'content_types' => ['album'], 'forwarded' => 'no']));
        $this->ingest($source, [$this->message(10, 'first', '777')]);
        self::assertSame('rejected', $this->decision()['selection_status']);
        $this->ingest($source, [$this->message(11, 'second', '777')]);
        self::assertSame('approved', $this->decision()['selection_status']);
        self::assertSame(1, $this->db->table('source_items')->count());
        self::assertSame(1, $this->db->table('source_selection_decisions')->count());
        $this->ingest($source, [$this->message(11, 'changed', '777', ['edit_date' => '2026-10-05T11:00:00Z'])]);
        self::assertSame('rejected', $this->decision()['selection_status']);
        self::assertFalse($this->ingest($source, [$this->message(11, 'second', '777')]));
    }

    public function testHistoricalUnknownForwardRequiresReviewAndLinksUseEntities(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $ctx = $this->contextFor($workspace, $owner);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $service = $this->app->container()->get(SelectionService::class);
        $service->saveRules($ctx, $source, SelectionRules::fromInput(['links' => 'yes', 'forwarded' => 'no']));
        $message = $this->message(10, 'Ссылка', extra: ['entities' => [['_' => 'MessageEntityTextUrl','url' => 'https://example.org','offset' => 0,'length' => 6]]]);
        unset($message['forward']);
        $this->ingest($source, [$message]);
        self::assertSame('needs_review', $this->decision()['selection_status']);
        self::assertSame('forwarded.unknown', $this->decision()['matched_rule']);
    }

    public function testSelectionFailureRollsBackTheEntireIngressAndRetrySucceeds(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Source', 'telegram', '@sample_channel', true);
        $this->db->execute('ALTER TABLE source_selection_decisions RENAME COLUMN matched_rule TO unavailable_matched_rule');
        try {
            try {
                $this->ingest($source, [$this->message(10)]);
                self::fail('Selection storage failure must prevent commit');
            } catch (\PDOException) {
                foreach (['source_items', 'source_messages', 'source_events', 'source_selection_decisions'] as $table) {
                    self::assertSame(0, $this->db->table($table)->count());
                }
            }
        } finally {
            $this->db->execute('ALTER TABLE source_selection_decisions RENAME COLUMN unavailable_matched_rule TO matched_rule');
        }
        self::assertTrue($this->ingest($source, [$this->message(10)]));
        self::assertSame('needs_review', $this->decision()['selection_status']);
    }

    public function testSelectionServiceEnforcesWorkspaceScopeAndDoesNotTreatDocumentsAsPhotos(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        [$other, $otherWorkspace] = $this->ownerWithWorkspace('other@example.com');
        $ctx = $this->contextFor($workspace, $owner);
        $foreign = $this->contextFor($otherWorkspace, $other);
        $source = $this->app->container()->get(SourceService::class)->create($ctx, 'Source', 'telegram', '@sample_channel', true);
        $service = $this->app->container()->get(SelectionService::class);
        $service->saveRules($ctx, $source, SelectionRules::fromInput(['content_types' => ['photo']]));
        self::assertNull($service->rules($foreign, $source));
        try {
            $service->saveRules($foreign, $source, SelectionRules::fromInput([]));
            self::fail('Foreign Source cannot be changed');
        } catch (\App\Kernel\Exception\HttpException $e) {
            self::assertSame(404, $e->status);
        }
        $this->ingest($source, [$this->message(10, extra: ['media' => ['kind' => 'document']])]);
        self::assertSame('rejected', $this->decision()['selection_status']);
        $item = $this->db->table('source_items')->first();
        self::assertNotNull($item);
        self::assertSame('other', $item['content_type']);
        try {
            $service->decide($foreign, $source, $item['public_id'], true);
            self::fail('Foreign material cannot be approved');
        } catch (\App\Kernel\Exception\HttpException $e) {
            self::assertSame(404, $e->status);
        }
    }

    public function testSelectionMigrationBackfillRollbackAndReplay(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $source = $this->app->container()->get(SourceService::class)->create($this->contextFor($workspace, $owner), 'Source', 'telegram', '@sample_channel', true);
        $this->ingest($source, [$this->message(10)]);
        $migration = require TestEnv::basePath() . '/database/migrations/2026_10_05_000022_create_source_selection.php';
        $discovery = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $discovery->down($this->db);
        $migration->down($this->db);
        try {
            $migration->up($this->db);
            self::assertSame('needs_review', $this->decision()['selection_status']);
            self::assertSame('rules.not_configured', $this->decision()['matched_rule']);
            self::assertSame(1, $this->db->table('source_items')->count());
            self::assertSame(0, $this->db->table('source_selection_rules')->count());
        } finally {
            if ($this->db->select("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='source_selection_decisions'") === []) {
                $migration->up($this->db);
            }
            $discovery->up($this->db);
        }
    }
}
