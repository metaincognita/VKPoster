<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        $db->execute('CREATE TABLE content_storage_objects (storage_key VARCHAR(255) PRIMARY KEY, created_at DATETIME(6) NOT NULL, deleted_at DATETIME(6) NULL, KEY storage_retention (deleted_at, created_at)) ENGINE=InnoDB');
    }
    public function down(Connection $db): void
    {
        // Rollback forgets the cleanup index, never removes archived files.
        $db->execute('DROP TABLE IF EXISTS content_storage_objects');
    }
};
