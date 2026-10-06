<?php

declare(strict_types=1);

namespace App\Tests\Integration\Source;

use App\Kernel\Database\Migration;
use App\Kernel\Database\Migrator;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** The real Sources DDL is exercised only in app_test, never in the local application database. */
final class SourceMigrationTest extends TestCase
{
    public function testSourcesMigrationRollbackAndReplayAndColumnDefaults(): void
    {
        $db = TestEnv::connection();
        self::assertSame('app_test', $db->select('SELECT DATABASE() AS name')[0]['name']);
        $path = TestEnv::basePath() . '/database/migrations/2026_10_05_000013_create_sources.php';
        /** @var Migration $migration */
        $migration = require $path;
        $directory = sys_get_temp_dir() . '/sources-migration-' . bin2hex(random_bytes(4));
        mkdir($directory);
        copy($path, $directory . '/2026_10_05_000013_create_sources.php');
        $migrator = new Migrator($db, $directory, 'migrations_sources_test');
        $incoming = require TestEnv::basePath() . '/database/migrations/2026_10_05_000021_create_source_incoming.php';
        $selection = require TestEnv::basePath() . '/database/migrations/2026_10_05_000022_create_source_selection.php';
        $processing = require TestEnv::basePath() . '/database/migrations/2026_10_05_000023_create_source_text_processings.php';
        $images = require TestEnv::basePath() . '/database/migrations/2026_10_06_000024_create_source_image_processing.php';
        $videos = require TestEnv::basePath() . '/database/migrations/2026_10_06_000025_create_source_video_generations.php';
        $discovery = require TestEnv::basePath() . '/database/migrations/2026_10_06_000026_create_content_discovery.php';
        $origins = require TestEnv::basePath() . '/database/migrations/2026_10_06_000028_create_content_post_origins.php';
        $origins->down($db);
        $discovery->down($db);
        $videos->down($db);
        $images->down($db);
        $processing->down($db);
        $selection->down($db);
        $incoming->down($db);
        $migration->down($db);
        try {
            self::assertSame(['2026_10_05_000013_create_sources'], $migrator->migrate());
            $columns = $db->select('SHOW COLUMNS FROM sources');
            self::assertSame(['id', 'public_id', 'workspace_id', 'name', 'type', 'telegram_username', 'status', 'enabled', 'created_by', 'created_at', 'updated_at'], array_column($columns, 'Field'));
            $defaults = array_column($columns, 'Default', 'Field');
            self::assertSame('not_connected', $defaults['status']);
            self::assertSame('0', $defaults['enabled']);
            self::assertArrayNotHasKey('selection_rules_json', $defaults);
            self::assertSame(['2026_10_05_000013_create_sources'], $migrator->rollback());
            self::assertSame([], $db->select('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['sources']));
            self::assertSame(['2026_10_05_000013_create_sources'], $migrator->migrate());
            self::assertSame([], $migrator->migrate());
        } finally {
            if ($db->select('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', ['sources']) === []) {
                $migration->up($db);
            }
            $incoming->up($db);
            $selection->up($db);
            $processing->up($db);
            $images->up($db);
            $videos->up($db);
            $discovery->up($db);
            $origins->up($db);
            $db->execute('DROP TABLE IF EXISTS migrations_sources_test');
            unlink($directory . '/2026_10_05_000013_create_sources.php');
            rmdir($directory);
        }
    }
}
