<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Durable review fences; additive migration preserves original materials and historical results. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $columns = [
            'sources' => ['connection_version' => 'INT UNSIGNED NOT NULL DEFAULT 1'],
            'source_items' => ['connection_version' => 'INT UNSIGNED NOT NULL DEFAULT 1'],
            'source_selection_decisions' => ['revision_hash' => 'CHAR(64) NULL'],
            'source_video_generations' => ['lease_generation' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'error_category' => 'VARCHAR(64) NULL'],
            'semantic_selection_evaluations' => ['attempt_count' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'input_json' => 'JSON NULL', 'started_at' => 'DATETIME(6) NULL', 'error_category' => 'VARCHAR(64) NULL'],
            'source_text_processings' => ['error_category' => 'VARCHAR(64) NULL', 'retryable' => 'BOOLEAN NOT NULL DEFAULT FALSE'],
        ];
        foreach ($columns as $table => $definitions) {
            $present = array_column($db->select('SHOW COLUMNS FROM ' . $table), 'Field');
            foreach ($definitions as $column => $definition) {
                if (!in_array($column, $present, true)) {
                    $db->execute('ALTER TABLE ' . $table . ' ADD ' . $column . ' ' . $definition);
                }
            }
        }
        $this->bindLegacyDecisions($db);
    }

    /** Preserve proven-current legacy results when replacing the timestamp-based decision identity. */
    private function bindLegacyDecisions(Connection $db): void
    {
        $cursor = 0;
        do {
            $rows = $db->select('SELECT * FROM source_selection_decisions WHERE revision_hash IS NULL AND id>? ORDER BY id LIMIT 100', [$cursor]);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $db->transaction(function () use ($db, $row): void {
                    $row = $db->select('SELECT * FROM source_selection_decisions WHERE id=? FOR UPDATE', [$row['id']])[0];
                    if ($row['revision_hash'] !== null) {
                        return;
                    }
                    $item = $db->select('SELECT * FROM source_items WHERE workspace_id=? AND id=?', [$row['workspace_id'], $row['item_id']])[0] ?? null;
                    if ($item === null) {
                        return;
                    }
                    $messages = $db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id=? AND item_id=? ORDER BY message_id', [$row['workspace_id'], $row['item_id']]);
                    $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $messages);
                    $legacy = $row;
                    unset($legacy['revision_hash']);
                    $oldHash = hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR));
                    $proof = $db->select("SELECT id FROM source_text_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? AND status='completed' LIMIT 1", [$row['workspace_id'], $row['item_id'], $revision, $oldHash]) !== [];
                    if ($row['decision_mode'] !== 'manual' || $proof) {
                        $row['revision_hash'] = $revision;
                    } else {
                        $row['selection_status'] = 'needs_review';
                        $row['reason'] = 'Подтвердите актуальную ревизию: прежнее ручное решение не содержит её идентификатора.';
                        $row['matched_rule'] = 'manual.revision_unverified';
                    }
                    $newHash = \App\Domain\Content\Processing\MaterialRepository::selectionHash($row);
                    $db->execute('UPDATE source_selection_decisions SET revision_hash=?, selection_status=?, reason=?, matched_rule=? WHERE workspace_id=? AND id=?', [$row['revision_hash'], $row['selection_status'], $row['reason'], $row['matched_rule'], $row['workspace_id'], $row['id']]);
                    if ($row['revision_hash'] === null) {
                        return;
                    }
                    foreach (['source_text_processings', 'source_image_processings', 'source_video_generations'] as $table) {
                        $db->execute('UPDATE ' . $table . ' SET selection_hash=? WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=?', [$newHash, $row['workspace_id'], $row['item_id'], $revision, $oldHash]);
                    }
                    $key = hash('sha256', 'material:' . $row['item_id'] . ':' . $revision . ':' . $newHash);
                    $db->execute('UPDATE content_post_origins SET selection_hash=?, idempotency_key=? WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=?', [$newHash, $key, $row['workspace_id'], $row['item_id'], $revision, $oldHash]);
                });
            }
        } while (count($rows) === 100);
    }

    public function down(Connection $db): void
    {
        $db->execute("DELETE FROM jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.class')) = ?", [\App\Jobs\Content\SemanticSelectionJob::class]);
        $db->execute('ALTER TABLE source_text_processings DROP error_category, DROP retryable');
        $db->execute('ALTER TABLE semantic_selection_evaluations DROP attempt_count, DROP input_json, DROP started_at, DROP error_category');
        $db->execute('ALTER TABLE source_video_generations DROP lease_generation, DROP error_category');
        $db->execute('ALTER TABLE source_selection_decisions DROP revision_hash');
        $db->execute('ALTER TABLE source_items DROP connection_version');
        $db->execute('ALTER TABLE sources DROP connection_version');
    }
};
