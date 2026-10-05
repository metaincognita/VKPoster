<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE source_selection_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL UNIQUE, version INT UNSIGNED NOT NULL,
            rules_json JSON NOT NULL, updated_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE source_selection_decisions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL,
            source_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NOT NULL UNIQUE,
            selection_status VARCHAR(16) NOT NULL, decision_mode VARCHAR(16) NOT NULL,
            reason VARCHAR(512) NOT NULL, matched_rule VARCHAR(64) NOT NULL,
            rules_version INT UNSIGNED NULL, rules_snapshot_json JSON NULL, decided_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            KEY idx_selection_status (workspace_id, source_id, selection_status, item_id),
            FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE CASCADE,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("INSERT INTO source_selection_decisions (workspace_id, source_id, item_id, selection_status, decision_mode, reason, matched_rule, created_at, updated_at)
            SELECT workspace_id, source_id, id, 'needs_review', 'automatic', 'Правила отбора ещё не настроены.', 'rules.not_configured', created_at, updated_at FROM source_items");
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS source_selection_decisions');
        $db->execute('DROP TABLE IF EXISTS source_selection_rules');
    }
};
