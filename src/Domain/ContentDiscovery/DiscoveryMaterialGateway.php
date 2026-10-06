<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Explicit discovery-to-content boundary: permitted summaries only, no invented Source or Telegram IDs, no PostDraft. */
final class DiscoveryMaterialGateway
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly DiscoveryRepository $discovery, private readonly AuditLog $audit, private readonly \App\Domain\Source\Selection\SelectionService $selection)
    {
    }
    /** Import once with provenance and existing optional selection; default needs_review, manual override retained. */
    public function import(WorkspaceContext $ctx, string $discoveryId): string
    {
        return $this->db->transaction(function () use ($ctx, $discoveryId): string {
            $this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]);
            $item = $this->discovery->item($ctx, $discoveryId);
            $prior = $this->db->select('SELECT i.public_id FROM discovery_imports l JOIN source_items i ON i.id = l.material_id AND i.workspace_id = l.workspace_id WHERE l.workspace_id = ? AND l.discovery_item_id = ?', [$ctx->workspaceId, $item['id']])[0] ?? null;
            if ($prior !== null) {
                return (string) $prior['public_id'];
            }
            $cluster = $this->db->select('SELECT status FROM discovery_clusters WHERE workspace_id = ? AND id = ?', [$ctx->workspaceId, $item['cluster_id']])[0];
            if ($item['status'] === 'ignored' || $cluster['status'] === 'ignored') {
                throw new HttpException(409, 'Тема проигнорирована. Выберите другой материал.');
            }
            $now = DbTime::format($this->clock->now());
            $id = (string) new Ulid();
            $this->db->table('source_items')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'source_id' => null, 'peer_id' => 'discovery', 'item_key' => 'discovery:' . $discoveryId, 'text' => $item['title'] . "\n\n" . $item['excerpt'] . "\n\n" . $item['canonical_url'], 'content_type' => 'text', 'published_at' => $item['published_at'] ?? $item['discovered_at'], 'created_at' => $now, 'updated_at' => $now]);
            $materialId = (int) $this->db->lastInsertId();
            $this->db->table('discovery_imports')->insert(['workspace_id' => $ctx->workspaceId, 'discovery_item_id' => $item['id'], 'material_id' => $materialId, 'imported_by' => $ctx->userId, 'created_at' => $now]);
            $this->db->table('source_selection_decisions')->insert(['workspace_id' => $ctx->workspaceId, 'source_id' => null, 'item_id' => $materialId, 'selection_status' => 'needs_review', 'decision_mode' => 'automatic', 'reason' => 'Материал из радара ожидает ручного решения.', 'matched_rule' => 'discovery.import', 'created_at' => $now, 'updated_at' => $now]);
            $this->selection->evaluateDiscoveryMaterialLocked($ctx->workspaceId, $materialId);
            $this->db->execute('UPDATE discovery_items SET status = ?, updated_at = ? WHERE workspace_id = ? AND id = ?', ['imported', $now, $ctx->workspaceId, $item['id']]);
            $this->db->execute('UPDATE discovery_clusters SET status = ?, updated_at = ? WHERE workspace_id = ? AND id = ?', ['imported', $now, $ctx->workspaceId, $item['cluster_id']]);
            $this->audit->record('discovery.imported', $ctx->userId, 'discovery_item', $discoveryId, [], $ctx->workspaceId);
            return $id;
        });
    }
    /** Decision is independent of discovery/technical status and uses the same content selection table and guards. */
    public function decide(WorkspaceContext $ctx, string $materialId, bool $approve): void
    {
        $this->db->transaction(function () use ($ctx, $materialId, $approve): void {
            // Same lock order as Radar policy/import: concurrent recomputation cannot overwrite a manual override.
            $this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]);
            $item = $this->discovery->material($ctx, $materialId);
            $this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $item['id']]);
            $this->db->execute('UPDATE source_selection_decisions SET selection_status = ?, decision_mode = ?, reason = ?, matched_rule = ?, decided_by = ?, updated_at = ? WHERE workspace_id = ? AND item_id = ? AND source_id IS NULL', [$approve ? 'approved' : 'rejected', 'manual', $approve ? 'Принято вручную.' : 'Отклонено вручную.', $approve ? 'manual.approve' : 'manual.reject', $ctx->userId, DbTime::format($this->clock->now()), $ctx->workspaceId, $item['id']]);
            $this->audit->record($approve ? 'discovery.material_approved' : 'discovery.material_rejected', $ctx->userId, 'source_item', $materialId, [], $ctx->workspaceId);
        });
    }
}
