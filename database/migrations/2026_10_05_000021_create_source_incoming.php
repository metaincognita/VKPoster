<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE source_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL, event_id CHAR(64) NOT NULL, event_type VARCHAR(16) NOT NULL,
            payload_hash CHAR(64) NOT NULL, payload_json JSON NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'stored',
            created_at DATETIME(6) NOT NULL, UNIQUE KEY uq_source_event (source_id, event_id),
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE source_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL,
            peer_id VARCHAR(24) NOT NULL, item_key VARCHAR(64) NOT NULL, grouped_id VARCHAR(24) NULL,
            text LONGTEXT NOT NULL, content_type VARCHAR(16) NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'stored',
            published_at DATETIME(6) NOT NULL, edited_at DATETIME(6) NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_source_item (source_id, peer_id, item_key), KEY idx_source_recent (source_id, published_at),
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE source_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL,
            peer_id VARCHAR(24) NOT NULL, message_id BIGINT UNSIGNED NOT NULL, grouped_id VARCHAR(24) NULL,
            text LONGTEXT NOT NULL, entities_json JSON NOT NULL, media_json JSON NULL, metadata_json JSON NOT NULL,
            published_at DATETIME(6) NOT NULL, edited_at DATETIME(6) NULL, revision_hash CHAR(64) NOT NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_source_message (source_id, peer_id, message_id),
            FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE CASCADE,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS source_messages');
        $db->execute('DROP TABLE IF EXISTS source_items');
        $db->execute('DROP TABLE IF EXISTS source_events');
    }
};
