<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE content_automation_settings (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, scope_key VARCHAR(64) NOT NULL, source_id BIGINT UNSIGNED NULL, version INT UNSIGNED NOT NULL DEFAULT 1, settings_json JSON NOT NULL, next_discovery_at DATETIME(6) NULL, scan_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0, updated_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, UNIQUE KEY automation_scope (workspace_id, scope_key), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE) ENGINE=InnoDB");
        $db->execute("CREATE TABLE content_automation_runs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, settings_id BIGINT UNSIGNED NOT NULL, item_id BIGINT UNSIGNED NULL, run_key CHAR(64) NOT NULL, revision_hash CHAR(64) NULL, selection_hash CHAR(64) NULL, settings_version INT UNSIGNED NOT NULL, settings_snapshot_json JSON NOT NULL, status VARCHAR(32) NOT NULL DEFAULT 'pending', step VARCHAR(32) NOT NULL DEFAULT 'selection', last_successful_step VARCHAR(32) NULL, attempts INT UNSIGNED NOT NULL DEFAULT 0, text_version CHAR(26) NULL, video_version CHAR(26) NULL, draft_id BIGINT UNSIGNED NULL, queued_at DATETIME(6) NULL, available_at DATETIME(6) NOT NULL, error VARCHAR(255) NULL, created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NULL, UNIQUE KEY automation_run (workspace_id, run_key), KEY automation_due (status, available_at), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE, FOREIGN KEY (settings_id) REFERENCES content_automation_settings(id) ON DELETE CASCADE, FOREIGN KEY (item_id) REFERENCES source_items(id) ON DELETE CASCADE) ENGINE=InnoDB");
        $db->execute("CREATE TABLE content_automation_calls (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, workspace_id BIGINT UNSIGNED NOT NULL, operation_key CHAR(64) NOT NULL, operation VARCHAR(32) NOT NULL, created_at DATETIME(6) NOT NULL, UNIQUE KEY automation_call (workspace_id, operation_key), FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE) ENGINE=InnoDB");
    }
    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS content_automation_calls');
        $db->execute('DROP TABLE IF EXISTS content_automation_runs');
        $db->execute('DROP TABLE IF EXISTS content_automation_settings');
    }
};
