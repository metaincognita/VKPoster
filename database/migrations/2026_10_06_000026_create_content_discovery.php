<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute("CREATE TABLE discovery_clusters (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, title VARCHAR(512) NOT NULL, summary VARCHAR(2000) NOT NULL,
            tokens_json JSON NOT NULL, story_at DATETIME(6) NOT NULL, discovered_at DATETIME(6) NOT NULL,
            trend_score TINYINT UNSIGNED NOT NULL DEFAULT 0, score_json JSON NOT NULL, status VARCHAR(16) NOT NULL DEFAULT 'new',
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            KEY idx_radar (workspace_id, status, trend_score, discovered_at),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE discovery_items (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, cluster_id BIGINT UNSIGNED NOT NULL,
            provider VARCHAR(32) NOT NULL, source_type VARCHAR(16) NOT NULL, source_key VARCHAR(128) NOT NULL,
            source_name VARCHAR(255) NOT NULL, canonical_url VARCHAR(2048) NOT NULL, external_id VARCHAR(255) NULL,
            title VARCHAR(512) NOT NULL, excerpt VARCHAR(2000) NOT NULL, normalized_title VARCHAR(512) NOT NULL,
            fingerprint CHAR(64) NOT NULL, published_at DATETIME(6) NULL, discovered_at DATETIME(6) NOT NULL,
            metadata_json JSON NOT NULL, engagement_json JSON NOT NULL, providers_json JSON NOT NULL,
            language VARCHAR(16) NULL, trend_score TINYINT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(16) NOT NULL DEFAULT 'new',
            created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NOT NULL,
            KEY idx_discovery_cluster (workspace_id, cluster_id, id),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (cluster_id) REFERENCES discovery_clusters(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE discovery_item_keys (
            workspace_id BIGINT UNSIGNED NOT NULL, key_type VARCHAR(16) NOT NULL, key_hash CHAR(64) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (workspace_id, key_type, key_hash),
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (item_id) REFERENCES discovery_items(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $db->execute("CREATE TABLE discovery_runs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, public_id CHAR(26) NOT NULL UNIQUE,
            workspace_id BIGINT UNSIGNED NOT NULL, provider VARCHAR(32) NOT NULL, status VARCHAR(16) NOT NULL,
            found_count INT UNSIGNED NOT NULL DEFAULT 0, created_count INT UNSIGNED NOT NULL DEFAULT 0,
            error VARCHAR(512) NULL, created_at DATETIME(6) NOT NULL, finished_at DATETIME(6) NULL,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Discovery is a separate origin, not a fabricated Telegram Source. Shared material processing can accept it explicitly.
        foreach (['source_items', 'source_selection_decisions', 'source_text_processings'] as $table) {
            $db->execute('ALTER TABLE ' . $table . ' MODIFY source_id BIGINT UNSIGNED NULL');
        }
        $db->execute("CREATE TABLE discovery_imports (
            workspace_id BIGINT UNSIGNED NOT NULL, discovery_item_id BIGINT UNSIGNED NOT NULL UNIQUE,
            material_id BIGINT UNSIGNED NOT NULL UNIQUE, imported_by BIGINT UNSIGNED NULL, created_at DATETIME(6) NOT NULL,
            FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
            FOREIGN KEY (discovery_item_id) REFERENCES discovery_items(id) ON DELETE CASCADE,
            FOREIGN KEY (material_id) REFERENCES source_items(id) ON DELETE CASCADE,
            FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
    public function down(Connection $db): void
    {
        // Only materials created through this boundary are removed; pre-existing Source materials are retained.
        $db->execute('DELETE i FROM source_items i JOIN discovery_imports d ON d.material_id = i.id AND d.workspace_id = i.workspace_id WHERE i.source_id IS NULL');
        $db->execute('DROP TABLE IF EXISTS discovery_imports');
        foreach (['source_text_processings', 'source_selection_decisions', 'source_items'] as $table) {
            $db->execute('ALTER TABLE ' . $table . ' MODIFY source_id BIGINT UNSIGNED NOT NULL');
        }
        $db->execute('DROP TABLE IF EXISTS discovery_runs');
        $db->execute('DROP TABLE IF EXISTS discovery_item_keys');
        $db->execute('DROP TABLE IF EXISTS discovery_items');
        $db->execute('DROP TABLE IF EXISTS discovery_clusters');
    }
};
