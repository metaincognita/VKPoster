<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Exception\HttpException;

/** Scoped radar read model, provider run history and immutable import links. */
final class DiscoveryRepository extends WorkspaceScopedRepository
{
    public function __construct(\App\Kernel\Database\Connection $db, private readonly \App\Domain\Source\Selection\SemanticSelection $semantic)
    {
        parent::__construct($db);
    }
    /** @return list<array<string,mixed>> */
    public function clusters(WorkspaceContext $ctx, string $status = 'new', string $ranking = 'trend'): array
    {
        if (!in_array($ranking, ['trend', 'semantic'], true)) {
            throw new HttpException(422, 'Выберите порядок тем из списка.');
        }
        if (!in_array($status, ['new', 'imported', 'ignored'], true)) {
            throw new HttpException(422, 'Выберите статус из списка.');
        }
        $rows = $this->scoped($ctx, 'discovery_clusters')->where('status', '=', $status)->orderBy('trend_score', 'DESC')->orderBy('id', 'DESC')->limit(100)->get();
        foreach ($rows as &$row) {
            $row['score'] = json_decode((string) $row['score_json'], true, 32, JSON_THROW_ON_ERROR);
            $row['items'] = $this->db->select('SELECT d.*, i.public_id AS material_public_id FROM discovery_items d LEFT JOIN discovery_imports l ON l.discovery_item_id = d.id AND l.workspace_id = d.workspace_id LEFT JOIN source_items i ON i.id = l.material_id AND i.workspace_id = l.workspace_id WHERE d.workspace_id = ? AND d.cluster_id = ? ORDER BY d.id LIMIT 100', [$ctx->workspaceId, $row['id']]);
            $row['semantic_score'] = null;
            foreach ($row['items'] as &$item) {
                $item['semantic'] = $this->semantic->current($ctx->workspaceId, null, 'discovery', (int) $item['id'], \App\Domain\Source\Selection\SemanticSelection::candidateRevision($item));
                if ($item['semantic'] !== null && $item['semantic']['score'] !== null) {
                    $row['semantic_score'] = max($row['semantic_score'] ?? 0, (int) $item['semantic']['score']);
                }
            }
            unset($item);
        }
        unset($row);
        if ($ranking === 'semantic') {
            usort($rows, static function (array $a, array $b): int {
                $score = ($b['semantic_score'] ?? -1) <=> ($a['semantic_score'] ?? -1);
                return $score !== 0 ? $score : ((int) $b['trend_score'] <=> (int) $a['trend_score']);
            });
        }
        return $rows;
    }
    /** @return array<string,mixed> */
    public function item(WorkspaceContext $ctx, string $id): array
    {
        return $this->scoped($ctx, 'discovery_items')->where('public_id', '=', $id)->first() ?? throw new HttpException(404, 'Not found');
    }
    /** @return list<array<string,mixed>> */
    public function runs(WorkspaceContext $ctx): array
    {
        return $this->scoped($ctx, 'discovery_runs')->orderBy('id', 'DESC')->limit(10)->get();
    }
    /** @return array<string,mixed> */
    public function material(WorkspaceContext $ctx, string $id): array
    {
        return $this->db->select('SELECT i.* FROM source_items i JOIN discovery_imports l ON l.material_id = i.id AND l.workspace_id = i.workspace_id WHERE i.workspace_id = ? AND i.public_id = ? AND i.source_id IS NULL', [$ctx->workspaceId, $id])[0] ?? throw new HttpException(404, 'Not found');
    }
}
