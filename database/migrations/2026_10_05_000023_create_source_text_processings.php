<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE source_text_processings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL,
            revision_hash CHAR(64) NOT NULL, selection_hash CHAR(64) NOT NULL,
            original_text MEDIUMTEXT NOT NULL, processed_text MEDIUMTEXT NULL,
            mode VARCHAR(16) NOT NULL, settings_version INT UNSIGNED NOT NULL, settings_json JSON NOT NULL,
            status VARCHAR(16) NOT NULL, error VARCHAR(512) NULL, provider VARCHAR(32) NOT NULL,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            UNIQUE KEY uq_item_settings_version (item_id, settings_version),
            KEY idx_text_history (workspace_id, source_id, item_id, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS source_text_processings');
    }
};
