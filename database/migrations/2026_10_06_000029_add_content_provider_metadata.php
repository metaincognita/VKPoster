<?php

declare(strict_types=1);

use App\Kernel\Database\Connection;
use App\Kernel\Database\Migration;

return new class () implements Migration {
    public function up(Connection $db): void
    {
        foreach (['source_text_processings', 'semantic_selection_evaluations', 'source_image_variants', 'source_video_generations'] as $table) {
            $db->execute('ALTER TABLE ' . $table . ' ADD provider_metadata_json JSON NULL');
        }
        $db->execute('ALTER TABLE source_video_generations ADD provider_job_id VARCHAR(64) NULL, ADD poll_claimed_at DATETIME(6) NULL');
    }
    public function down(Connection $db): void
    {
        $db->execute('ALTER TABLE source_video_generations DROP poll_claimed_at, DROP provider_job_id');
        foreach (['source_video_generations', 'source_image_variants', 'semantic_selection_evaluations', 'source_text_processings'] as $table) {
            $db->execute('ALTER TABLE ' . $table . ' DROP provider_metadata_json');
        }
    }
};
