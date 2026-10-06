<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE semantic_selection_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            scope_key VARCHAR(64) NOT NULL, version INT UNSIGNED NOT NULL, settings_json JSON NOT NULL,
            updated_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_semantic_scope (workspace_id, scope_key),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Polymorphic origin IDs are internal only; domain lookups always authorize the actual workspace origin first.
        $db->execute("CREATE TABLE semantic_selection_evaluations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            scope_key VARCHAR(64) NOT NULL, origin_type VARCHAR(16) NOT NULL, origin_id BIGINT UNSIGNED NOT NULL,
            revision_hash CHAR(64) NOT NULL, deterministic_hash CHAR(64) NOT NULL, settings_version INT UNSIGNED NOT NULL, settings_snapshot_json JSON NOT NULL,
            provider VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL, deterministic_status VARCHAR(16) NOT NULL,
            deterministic_reason VARCHAR(512) NOT NULL, deterministic_rule VARCHAR(128) NOT NULL,
            decision VARCHAR(16) NULL, score TINYINT UNSIGNED NULL, confidence DECIMAL(5,4) NULL,
            reason VARCHAR(512) NULL, matched_criteria_json JSON NULL, review_flags_json JSON NULL,
            final_decision VARCHAR(16) NOT NULL, final_reason VARCHAR(512) NOT NULL, error VARCHAR(512) NULL,
            created_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_semantic_revision (workspace_id, scope_key, origin_type, origin_id, revision_hash, deterministic_hash, settings_version),
            KEY idx_semantic_history (workspace_id, origin_type, origin_id, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(Connection $db): void
    {
        // Removing optional policy must not leave an automatic AI approval active without its revision history.
        $db->execute("UPDATE source_selection_decisions SET selection_status = 'needs_review', reason = 'Смысловой модуль удалён. Проверьте материал вручную.', matched_rule = 'semantic.removed' WHERE decision_mode = 'automatic' AND matched_rule LIKE 'semantic.%'");
        $db->execute('DROP TABLE IF EXISTS semantic_selection_evaluations');
        $db->execute('DROP TABLE IF EXISTS semantic_selection_settings');
    }
};
