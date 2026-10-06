<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

/** Isolated connection identities and recoverable image operation outcomes; history is retained. */
return new class () implements Migration {
    public function up(Connection $db): void
    {
        $columns = array_column($db->select('SHOW COLUMNS FROM source_messages'), 'Field');
        if (!in_array('connection_version', $columns, true)) {
            $db->execute('ALTER TABLE source_messages ADD connection_version INT UNSIGNED NOT NULL DEFAULT 1');
            $db->execute('UPDATE source_messages m JOIN source_items i ON i.id=m.item_id SET m.connection_version=i.connection_version');
        }
        foreach (['source_items' => ['uq_source_item', 'source_id, connection_version, peer_id, item_key'], 'source_messages' => ['uq_source_message', 'source_id, connection_version, peer_id, message_id']] as $table => [$index, $keys]) {
            $indices = $db->select('SHOW INDEX FROM ' . $table . ' WHERE Key_name=?', [$index]);
            if (count($indices) === 3) {
                $db->execute('ALTER TABLE ' . $table . ' DROP INDEX ' . $index . ', ADD UNIQUE KEY ' . $index . ' (' . $keys . ')');
            }
        }
        $columns = array_column($db->select('SHOW COLUMNS FROM content_automation_calls'), 'Field');
        if (!in_array('status', $columns, true)) {
            $db->execute("ALTER TABLE content_automation_calls ADD status VARCHAR(16) NOT NULL DEFAULT 'uncertain', ADD error_category VARCHAR(64) NULL, ADD attempts INT UNSIGNED NOT NULL DEFAULT 1, ADD available_at DATETIME(6) NULL, ADD result_json JSON NULL");
        }
    }
    public function down(Connection $db): void
    {
        // Reject a destructive rollback rather than merge distinct historical generations.
        foreach (['source_items' => 'source_id, peer_id, item_key', 'source_messages' => 'source_id, peer_id, message_id'] as $table => $keys) {
            if ($db->select('SELECT COUNT(*) AS n FROM ' . $table . ' GROUP BY ' . $keys . ' HAVING COUNT(*)>1 LIMIT 1') !== []) {
                throw new LogicException('Connection history requires migration 33; rollback would merge identities.');
            }
        }
        $db->execute('ALTER TABLE source_items DROP INDEX uq_source_item, ADD UNIQUE KEY uq_source_item (source_id, peer_id, item_key)');
        $db->execute('ALTER TABLE source_messages DROP INDEX uq_source_message, ADD UNIQUE KEY uq_source_message (source_id, peer_id, message_id), DROP connection_version');
        $db->execute('ALTER TABLE content_automation_calls DROP status, DROP error_category, DROP attempts, DROP available_at, DROP result_json');
    }
};
