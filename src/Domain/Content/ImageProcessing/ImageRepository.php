<?php

declare(strict_types=1);

namespace App\Domain\Content\ImageProcessing;

use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Exception\HttpException;

/** Workspace-scoped image history and private variant lookup. */
final class ImageRepository extends WorkspaceScopedRepository
{
    /** @return list<array<string,mixed>> */
    public function history(WorkspaceContext $context, Source $source, int $itemId): array
    {
        $rows = $this->db->select('SELECT * FROM source_image_processings WHERE workspace_id = ? AND source_id = ? AND item_id = ? ORDER BY id DESC LIMIT 100', [$context->workspaceId, $source->id, $itemId]);
        foreach ($rows as &$row) {
            $row['variants'] = $this->scoped($context, 'source_image_variants')->where('processing_id', '=', $row['id'])->orderBy('id')->get();
            foreach ($row['variants'] as &$variant) {
                $variant['metrics'] = json_decode((string) $variant['metrics_json'], true, 32, JSON_THROW_ON_ERROR);
            }
            unset($variant);
        }
        unset($row);
        return $rows;
    }

    /** @return array<string,mixed> */
    public function variant(WorkspaceContext $context, Source $source, int $itemId, string $publicId): array
    {
        return $this->db->select('SELECT v.*, p.public_id AS processing_public_id, p.revision_hash, p.selection_hash, p.status FROM source_image_variants v JOIN source_image_processings p ON p.id = v.processing_id AND p.workspace_id = v.workspace_id WHERE v.workspace_id = ? AND p.source_id = ? AND p.item_id = ? AND v.public_id = ?', [$context->workspaceId, $source->id, $itemId, $publicId])[0] ?? throw new HttpException(404, 'Not found');
    }
}
