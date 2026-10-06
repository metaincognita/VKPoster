<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Source\Source;
use App\Domain\Source\SourceIngress;

/** Synthetic reader snapshots for selection tests. Never touches Telegram or publishing. */
abstract class SourceSelectionTestCase extends WorkspaceTestCase
{
    protected function tearDown(): void
    {
        // Legacy DDL rollback tests recreate their original tables; restore additive review columns.
        (require \App\Tests\Support\TestEnv::basePath() . '/database/migrations/2026_10_07_000032_review_fixes.php')->up(\App\Tests\Support\TestEnv::connection());
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function message(int $id, string $text = 'Наука #космос', ?string $group = null, array $extra = []): array
    {
        return array_replace(['channel_id' => '12345', 'message_id' => $id, 'grouped_id' => $group, 'text' => $text,
            'entities' => [], 'media' => $group === null ? null : ['kind' => 'photo'], 'forward' => null,
            'date' => '2026-10-05T10:00:00Z', 'edit_date' => null, 'content_hash' => hash('sha256', $text . $id)], $extra);
    }

    /** @param list<array<string, mixed>> $messages */
    protected function ingest(Source $source, array $messages): bool
    {
        $payload = ['peer_id' => '12345', 'grouped_id' => $messages[0]['grouped_id'], 'messages' => $messages];
        $accepted = $this->app->container()->get(SourceIngress::class)->accept(['version' => 1, 'connection_version' => $source->connectionVersion, 'source_id' => $source->publicId, 'kind' => 'item', 'payload' => $payload, 'event_id' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
        $this->drainSemantic();
        return $accepted;
    }

    /** Simulates the existing worker after the inbox transaction commits. */
    protected function drainSemantic(): void
    {
        $c = $this->app->container();
        if ($this->db->select("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='semantic_selection_evaluations'") === []) {
            return;
        }
        foreach ($this->db->select("SELECT id FROM semantic_selection_evaluations WHERE status='pending' ORDER BY id") as $row) {
            $c->get(\App\Domain\Source\Selection\SemanticSelection::class)->run((int) $row['id'], $c->get(\App\Domain\Source\Selection\SelectionService::class));
        }
        $this->db->execute("DELETE j FROM jobs j LEFT JOIN semantic_selection_evaluations e ON e.id=JSON_UNQUOTE(JSON_EXTRACT(j.payload_json, '$.data.attempt_id')) WHERE JSON_UNQUOTE(JSON_EXTRACT(j.payload_json, '$.class'))=? AND (e.id IS NULL OR e.status IN ('completed','failed','blocked','stale'))", [\App\Jobs\Content\SemanticSelectionJob::class]);
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    protected function decision(): array
    {
        $this->drainSemantic();
        return $this->db->table('source_selection_decisions')->first() ?? throw new \LogicException('Missing decision');
    }
}
