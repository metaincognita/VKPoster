<?php

declare(strict_types=1);

namespace App\Domain\Content\Processing;

use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Exception\HttpException;

/** Workspace-scoped material snapshots and immutable attempt history; Telegram items are never modified. */
final class MaterialRepository extends WorkspaceScopedRepository
{
    /** @return array<string, mixed> */
    public function item(WorkspaceContext $context, ?Source $source, string $publicId): array
    {
        if ($source === null) {
            return $this->db->select('SELECT i.* FROM source_items i JOIN discovery_imports l ON l.material_id = i.id AND l.workspace_id = i.workspace_id WHERE i.workspace_id = ? AND i.public_id = ? AND i.source_id IS NULL', [$context->workspaceId, $publicId])[0] ?? throw new HttpException(404, 'Not found');
        }
        return $this->scoped($context, 'source_items')->where('source_id', '=', $source->id)->where('public_id', '=', $publicId)->first() ?? throw new HttpException(404, 'Not found');
    }

    /** @return list<array<string, mixed>> */
    public function messages(WorkspaceContext $context, ?Source $source, int $itemId): array
    {
        return $this->db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id = ? AND source_id <=> ? AND item_id = ? ORDER BY message_id', [$context->workspaceId, $source?->id, $itemId]);
    }

    /** @return array<string, mixed>|null */
    public function selection(WorkspaceContext $context, ?Source $source, int $itemId): ?array
    {
        return $this->db->select('SELECT * FROM source_selection_decisions WHERE workspace_id = ? AND source_id <=> ? AND item_id = ?', [$context->workspaceId, $source?->id, $itemId])[0] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function history(WorkspaceContext $context, ?Source $source, int $itemId): array
    {
        return $this->db->select('SELECT * FROM source_text_processings WHERE workspace_id = ? AND source_id <=> ? AND item_id = ? ORDER BY id DESC LIMIT 50', [$context->workspaceId, $source?->id, $itemId]);
    }

    /**
     * @param array<string, mixed> $item
     * @param list<array<string, mixed>> $messages
     */
    public static function revision(array $item, array $messages): string
    {
        return hash('sha256', json_encode([$item['text'], $item['content_type'], $item['peer_id'], $item['grouped_id'], $messages], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed>|null $selection */
    public static function selectionHash(?array $selection): string
    {
        return hash('sha256', json_encode($selection, JSON_THROW_ON_ERROR));
    }
}
