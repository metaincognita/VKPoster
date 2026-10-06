<?php

declare(strict_types=1);

namespace App\Domain\Content\Publishing;

use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Post\Post;
use App\Domain\Post\PostException;
use App\Kernel\Database\Connection;

/** Provenance preflight for the existing publisher; ordinary posts are unaffected and copied drafts retain their guard. */
final class ContentOriginGuard
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** Safe user-facing failure, or null when an ordinary/approved current post can proceed. */
    public function problem(Post $post): ?string
    {
        $origin = $this->db->select('SELECT o.*, i.text, i.content_type, i.peer_id, i.grouped_id, i.source_id, i.connection_version, i.id AS material_id FROM content_post_origins o LEFT JOIN source_items i ON i.id = o.item_id AND i.workspace_id = o.workspace_id WHERE o.workspace_id = ? AND o.post_id = ?', [$post->workspaceId, $post->id])[0] ?? null;
        if ($origin === null) {
            return null;
        }
        $error = 'Исходный материал или решение отбора изменились. Создайте черновик из актуального принятого материала.';
        if ($origin['material_id'] === null || $origin['text_processing_id'] === null) {
            return $error;
        }
        if ($origin['source_id'] !== null) {
            $source = $this->db->select('SELECT connection_version FROM sources WHERE workspace_id=? AND id=?', [$post->workspaceId, $origin['source_id']])[0] ?? null;
            if ($source === null || (int) $source['connection_version'] !== (int) $origin['connection_version']) {
                return $error;
            }
        }
        $bindings = [$post->workspaceId, $origin['source_id'], $origin['item_id']];
        $messages = $this->db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id = ? AND source_id <=> ? AND item_id = ? ORDER BY message_id', $bindings);
        $selection = $this->db->select('SELECT * FROM source_selection_decisions WHERE workspace_id = ? AND source_id <=> ? AND item_id = ?', $bindings)[0] ?? null;
        if (($selection['selection_status'] ?? '') !== 'approved' || !hash_equals((string) $origin['revision_hash'], MaterialRepository::revision($origin, $messages)) || !hash_equals((string) $origin['selection_hash'], MaterialRepository::selectionHash($selection))) {
            return $error;
        }
        $text = $this->db->select('SELECT status, revision_hash, selection_hash FROM source_text_processings WHERE workspace_id = ? AND item_id = ? AND id = ?', [$post->workspaceId, $origin['item_id'], $origin['text_processing_id']])[0] ?? null;
        if (!$this->matches($text, $origin)) {
            return $error;
        }
        $snapshot = json_decode((string) $origin['snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
        if ($origin['source_id'] === null) {
            $candidate = $this->db->select('SELECT d.* FROM discovery_items d JOIN discovery_imports l ON l.discovery_item_id = d.id AND l.workspace_id = d.workspace_id WHERE l.workspace_id = ? AND l.material_id = ?', [$post->workspaceId, $origin['item_id']])[0] ?? null;
            if ($candidate === null || $snapshot['discovery_revision'] !== \App\Domain\Source\Selection\SemanticSelection::candidateRevision($candidate)) {
                return $error;
            }
        }
        foreach ($snapshot['image_variants'] as $id) {
            $image = $this->db->select('SELECT p.status, p.revision_hash, p.selection_hash FROM source_image_variants v JOIN source_image_processings p ON p.id = v.processing_id AND p.workspace_id = v.workspace_id WHERE v.workspace_id = ? AND p.item_id = ? AND v.public_id = ?', [$post->workspaceId, $origin['item_id'], $id])[0] ?? null;
            if (!$this->matches($image, $origin)) {
                return $error;
            }
        }
        if ($snapshot['video'] !== null) {
            $video = $this->db->select('SELECT status, revision_hash, selection_hash FROM source_video_generations WHERE workspace_id = ? AND item_id = ? AND public_id = ?', [$post->workspaceId, $origin['item_id'], $snapshot['video']])[0] ?? null;
            if (!$this->matches($video, $origin)) {
                return $error;
            }
        }
        return null;
    }

    public function assertCurrent(Post $post): void
    {
        $error = $this->problem($post);
        if ($error !== null) {
            throw new PostException($error);
        }
    }

    /** Explicit editor duplication preserves provenance instead of bypassing approval/revision checks. */
    public function copy(Post $original, Post $copy): void
    {
        $row = $this->db->select('SELECT * FROM content_post_origins WHERE workspace_id = ? AND post_id = ?', [$original->workspaceId, $original->id])[0] ?? null;
        if ($row !== null) {
            unset($row['id']);
            $row['post_id'] = $copy->id;
            $row['created_by'] = $copy->authorId;
            $row['created_at'] = \App\Support\DbTime::format($copy->createdAt);
            $row['idempotency_key'] = hash('sha256', 'copy:' . $copy->publicId);
            $this->db->table('content_post_origins')->insert($row);
        }
    }

    /**
     * @param array<string,mixed>|null $row
     * @param array<string,mixed> $origin
     */
    private function matches(?array $row, array $origin): bool
    {
        return $row !== null && $row['status'] === 'completed' && $row['revision_hash'] === $origin['revision_hash'] && $row['selection_hash'] === $origin['selection_hash'];
    }
}
