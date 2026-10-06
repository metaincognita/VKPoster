<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE content_post_origins (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workspace_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NULL, post_id BIGINT UNSIGNED NULL,
            text_processing_id BIGINT UNSIGNED NULL, revision_hash CHAR(64) NOT NULL, selection_hash CHAR(64) NOT NULL,
            idempotency_key CHAR(64) NOT NULL, snapshot_json JSON NOT NULL,
            created_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_content_export (workspace_id, idempotency_key), UNIQUE KEY uq_content_post (post_id),
            KEY idx_content_material (workspace_id, item_id, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE SET NULL,
            FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE SET NULL,
            FOREIGN KEY (text_processing_id) REFERENCES source_text_processings(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(Connection $db): void
    {
        if ($db->select("SELECT p.id FROM publications p JOIN content_post_origins o ON o.post_id = p.post_id AND o.workspace_id = p.workspace_id WHERE p.status = 'sending' LIMIT 1") !== []) {
            throw new LogicException('Stop active content publication workers before rollback.');
        }
        // Cancel outstanding derived publications before removing their provenance guard.
        $db->execute("UPDATE publications p JOIN content_post_origins o ON o.post_id = p.post_id AND o.workspace_id = p.workspace_id SET p.status = 'cancelled' WHERE p.status = 'queued'");
        $db->execute("UPDATE posts p JOIN content_post_origins o ON o.post_id = p.id AND o.workspace_id = p.workspace_id SET p.status = 'draft', p.scheduled_at = NULL WHERE p.status = 'scheduled'");
        $db->execute('DROP TABLE content_post_origins');
    }
};
