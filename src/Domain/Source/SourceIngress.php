<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Privileged reader boundary. Locks each Source and commits event, item and message snapshots atomically before ACK. */
final class SourceIngress
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SourceEventContract $contract, private readonly \App\Domain\Source\Selection\SelectionService $selection)
    {
    }

    /** @return list<array<string, mixed>> Enabled Telegram configurations only; no workspace credentials. */
    public function enabled(): array
    {
        return $this->db->select("SELECT public_id AS id, telegram_username AS username, connection_version FROM sources WHERE enabled = 1 AND type = 'telegram' ORDER BY id");
    }

    /** @param array<string, mixed> $event Returns only after commit; retries with the same event id are safe. */
    public function accept(array $event): bool
    {
        $this->contract->validate($event);
        /** @var array{connection_version?: int, source_id: string, event_id: string, kind: string, payload: array<string, mixed>} $event */
        $encoded = json_encode($event, JSON_THROW_ON_ERROR);
        return $this->db->transaction(function () use ($event, $encoded): bool {
            $rows = $this->db->select('SELECT * FROM sources WHERE public_id = ? FOR UPDATE', [$event['source_id']]);
            $source = $rows[0] ?? throw new HttpException(404, 'Source not found');
            $id = (int) $source['id'];
            if (($event['connection_version'] ?? 1) !== (int) $source['connection_version']) {
                throw new HttpException(422, 'Obsolete source connection');
            }
            $prior = $this->db->select('SELECT payload_hash FROM source_events WHERE source_id = ? AND event_id = ?', [$id, $event['event_id']]);
            $hash = hash('sha256', $encoded);
            if ($prior !== []) {
                if ($prior[0]['payload_hash'] !== $hash) {
                    throw new HttpException(409, 'Event identity collision');
                }
                return false;
            }
            if ((int) $source['enabled'] !== 1 || $source['type'] !== 'telegram') {
                throw new HttpException(409, 'Source is disabled');
            }
            $now = DbTime::format($this->clock->now());
            $this->db->table('source_events')->insert([
                'workspace_id' => (int) $source['workspace_id'], 'source_id' => $id, 'event_id' => $event['event_id'],
                'event_type' => $event['kind'], 'payload_hash' => $hash, 'payload_json' => $encoded, 'created_at' => $now,
            ]);
            if ($event['kind'] === 'item') {
                $this->item($source, $event['payload'], $now);
                $status = 'connected';
            } else {
                $status = $event['payload']['status'];
            }
            $this->db->execute('UPDATE sources SET status = ?, updated_at = ? WHERE id = ?', [$status, $now, $id]);
            return true;
        });
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $payload
     */
    private function item(array $source, array $payload, string $now): void
    {
        /** @var array{peer_id: string, grouped_id: string|null, messages: list<array{message_id: int, text: string, entities: array<array-key, mixed>, media: array<array-key, mixed>|null, date: string, edit_date: string|null, content_hash: string, forward?: mixed}>} $payload */
        $messages = $payload['messages'];
        $key = $payload['grouped_id'] === null ? 'message:' . $messages[0]['message_id'] : 'album:' . $payload['grouped_id'];
        $sourceId = (int) $source['id'];
        $workspaceId = (int) $source['workspace_id'];
        $peer = $payload['peer_id'];
        $this->db->execute(
            "INSERT INTO source_items (public_id, workspace_id, source_id, peer_id, item_key, grouped_id, text, content_type, published_at, created_at, updated_at, connection_version)
            VALUES (?, ?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
            [(string) new Ulid(), $workspaceId, $sourceId, $peer, $key, $payload['grouped_id'], $payload['grouped_id'] === null ? 'text' : 'album', $this->contract->date($messages[0]['date']), $now, $now, (int) $source['connection_version']]
        );
        $itemId = (int) $this->db->lastInsertId();
        foreach ($messages as $message) {
            $date = $this->contract->date($message['date']);
            $edit = $message['edit_date'] === null ? null : $this->contract->date($message['edit_date']);
            $old = $this->db->select('SELECT item_id, published_at, edited_at FROM source_messages WHERE source_id = ? AND peer_id = ? AND message_id = ?', [$sourceId, $peer, $message['message_id']]);
            if ($old !== []) {
                if ((int) $old[0]['item_id'] !== $itemId) {
                    throw new HttpException(409, 'Message group changed');
                }
                if ((string) ($old[0]['edited_at'] ?? $old[0]['published_at']) > ($edit ?? $date)) {
                    continue;
                }
            }
            $this->db->execute(
                'INSERT INTO source_messages (workspace_id, source_id, item_id, peer_id, message_id, grouped_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE text=VALUES(text), entities_json=VALUES(entities_json), media_json=VALUES(media_json), metadata_json=VALUES(metadata_json), edited_at=VALUES(edited_at), revision_hash=VALUES(revision_hash), updated_at=VALUES(updated_at)',
                [$workspaceId, $sourceId, $itemId, $peer, $message['message_id'], $payload['grouped_id'], $message['text'], json_encode($message['entities'], JSON_THROW_ON_ERROR), $message['media'] === null ? null : json_encode($message['media'], JSON_THROW_ON_ERROR), json_encode(['forward' => $message['forward'] ?? null, 'forward_known' => array_key_exists('forward', $message)], JSON_THROW_ON_ERROR), $date, $edit, $message['content_hash'], $now, $now]
            );
        }
        $members = $this->db->select('SELECT text, media_json, published_at, edited_at FROM source_messages WHERE item_id = ? ORDER BY message_id', [$itemId]);
        if ($members === []) {
            throw new HttpException(409, 'Empty item');
        }
        $text = implode("\n", array_filter(array_map(static fn (array $r): string => (string) $r['text'], $members), static fn (string $t): bool => $t !== ''));
        // Telegram documents/videos must not masquerade as photos in content selection.
        $media = $members[0]['media_json'] === null ? null : json_decode((string) $members[0]['media_json'], true, 32, JSON_THROW_ON_ERROR);
        $type = $payload['grouped_id'] === null ? ($media === null ? 'text' : (is_array($media) && ($media['kind'] ?? null) === 'photo' ? 'photo' : 'other')) : 'album';
        $dates = array_column($members, 'published_at');
        $edits = array_values(array_filter(array_column($members, 'edited_at'), 'is_string'));
        $this->db->execute('UPDATE source_items SET text = ?, content_type = ?, published_at = ?, edited_at = ?, updated_at = ? WHERE id = ?', [$text, $type, min($dates === [] ? [$now] : $dates), $edits === [] ? null : max($edits), $now, $itemId]);
        $this->selection->evaluateLocked($workspaceId, $sourceId, $itemId);
    }
}
