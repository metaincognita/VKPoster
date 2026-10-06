<?php

declare(strict_types=1);

namespace App\Domain\Content\VideoProcessing;

use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Exception\HttpException;

/** Workspace-scoped append-only generation history and per-attempt metadata. */
final class VideoRepository extends WorkspaceScopedRepository
{
    /** @return list<array<string,mixed>> */
    public function history(WorkspaceContext $ctx, Source $source, int $itemId): array
    {
        return $this->db->select('SELECT * FROM source_video_generations WHERE workspace_id = ? AND source_id = ? AND item_id = ? ORDER BY id DESC LIMIT 50', [$ctx->workspaceId, $source->id, $itemId]);
    }
    /** @return array<string,mixed> */
    public function attempt(WorkspaceContext $ctx, Source $source, int $itemId, string $publicId): array
    {
        return $this->scoped($ctx, 'source_video_generations')->where('source_id', '=', $source->id)->where('item_id', '=', $itemId)->where('public_id', '=', $publicId)->first() ?? throw new HttpException(404, 'Not found');
    }
}
