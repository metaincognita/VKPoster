<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        // Sources are content origins, independent of publishing channels. Selection rules belong to a future module.
        $db->execute('CREATE TABLE sources (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(26) NOT NULL,
            workspace_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(255) NOT NULL,
            type VARCHAR(32) NOT NULL,
            telegram_username VARCHAR(32) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT \'not_connected\',
            enabled BOOLEAN NOT NULL DEFAULT FALSE,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME(6) NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            UNIQUE KEY uq_sources_public (public_id),
            UNIQUE KEY uq_sources_workspace_locator (workspace_id, type, telegram_username),
            CONSTRAINT fk_sources_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces (id) ON DELETE CASCADE,
            CONSTRAINT fk_sources_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    public function down(Connection $db): void
    {
        $db->execute('DROP TABLE IF EXISTS sources');
    }
};
