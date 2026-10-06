<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;

/** Workspace-scoped incoming material read model; no processing or publishing side effects. */
final class SourceItemRepository extends WorkspaceScopedRepository
{
    /** @return list<array<string, mixed>> */
    public function recent(WorkspaceContext $context, Source $source, string $selectionStatus = ''): array
    {
        if (!in_array($selectionStatus, ['', 'approved', 'rejected', 'needs_review'], true)) {
            throw new \App\Kernel\Exception\HttpException(422, 'Invalid selection filter');
        }
        $sql = "SELECT i.*, COALESCE(d.selection_status, 'needs_review') AS selection_status,
            COALESCE(d.reason, 'Правила отбора ещё не настроены.') AS selection_reason, d.matched_rule, d.decision_mode,
            (SELECT GROUP_CONCAT(m.message_id ORDER BY m.message_id) FROM source_messages m WHERE m.item_id = i.id AND m.workspace_id = i.workspace_id) AS message_ids
            FROM source_items i LEFT JOIN source_selection_decisions d ON d.item_id = i.id AND d.workspace_id = i.workspace_id
            WHERE i.workspace_id = ? AND i.source_id = ? AND i.connection_version = ?";
        $bindings = [$context->workspaceId, $source->id, $source->connectionVersion];
        if ($selectionStatus !== '') {
            $sql .= " AND COALESCE(d.selection_status, 'needs_review') = ?";
            $bindings[] = $selectionStatus;
        }
        return $this->db->select($sql . ' ORDER BY i.published_at DESC, i.id DESC LIMIT 30', $bindings);
    }
}
