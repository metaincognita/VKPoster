<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE source_image_processings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, source_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL, telegram_message_id BIGINT UNSIGNED NOT NULL, peer_id VARCHAR(32) NOT NULL,
            telegram_photo_id VARCHAR(32) NOT NULL, revision_hash CHAR(64) NOT NULL, selection_hash CHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL DEFAULT 'queued', error VARCHAR(128) NULL, selected_variant CHAR(26) NULL,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            finished_at DATETIME(6) NULL,
            KEY idx_image_history (workspace_id, source_id, item_id, id), KEY idx_image_jobs (status, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE CASCADE,
            FOREIGN KEY (message_id) REFERENCES source_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE source_image_variants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, processing_id BIGINT UNSIGNED NOT NULL, kind VARCHAR(16) NOT NULL,
            storage_key VARCHAR(255) NOT NULL, preview_key VARCHAR(255) NOT NULL, sha256 CHAR(64) NOT NULL,
            width INT UNSIGNED NOT NULL, height INT UNSIGNED NOT NULL, mime VARCHAR(32) NOT NULL, bytes BIGINT UNSIGNED NOT NULL,
            quality VARCHAR(16) NOT NULL, metrics_json JSON NOT NULL, source_url VARCHAR(2048) NULL,
            verification VARCHAR(16) NOT NULL, confidence DECIMAL(6,5) NULL, verification_json JSON NOT NULL,
            provider VARCHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL,
            KEY idx_image_variants (workspace_id, processing_id, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (processing_id) REFERENCES source_image_processings(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS source_image_variants');
        $db->execute('DROP TABLE IF EXISTS source_image_processings');
    }
};
