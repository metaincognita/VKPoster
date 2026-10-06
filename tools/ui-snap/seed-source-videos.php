<?php

declare(strict_types=1);

// Synthetic video UI state, app_test only. Never touches live materials or Telegram sessions.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        $c = $this->app->container();
        $c->instance(\App\Integrations\Storage\MediaStorage::class, new \App\Tests\Support\ArrayMediaStorage());
        [$owner, $workspace] = $this->ownerWithWorkspace('video-ui@example.com', 'Video UI');
        $ctx = $this->contextFor($workspace, $owner);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Video Generation UI', 'telegram', '@video_ui_fixture', true);
        $this->ingest($source, [$this->message(10, 'Текст материала для демонстрации генерации видео', null, ['media' => ['kind' => 'photo', 'telegram_id' => '1010', 'selected' => ['type' => 'w', 'width' => 640, 'height' => 640, 'bytes' => 10000]]])]);
        $item = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item');
        $itemId = (string) $item['public_id'];
        $c->get(\App\Domain\Source\Selection\SelectionService::class)->decide($ctx, $source, $itemId, true);
        $materials = $c->get(\App\Domain\Content\Processing\MaterialRepository::class);
        $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $materials->messages($ctx, $source, (int) $item['id']));
        $c->get(\App\Domain\Content\Processing\ContentProcessor::class)->process($ctx, $source, $itemId, $revision, \App\Domain\Content\Processing\TextSettings::fromInput(['mode' => 'rewrite']));
        $text = $this->db->table('source_text_processings')->first() ?? throw new \RuntimeException('Missing text');
        $images = $c->get(\App\Domain\Content\ImageProcessing\ImageWorkflow::class);
        $images->request($ctx, $source, $itemId, $revision);
        $job = $images->jobs()[0];
        $bytes = \App\Tests\Support\SourceImageFixture::bytes();
        $images->accept(array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['data' => base64_encode($bytes), 'sha256' => hash('sha256', $bytes)]);
        $variant = $this->db->table('source_image_variants')->where('kind', '=', 'original')->first() ?? throw new \RuntimeException('Missing image');
        $workflow = $c->get(\App\Domain\Content\VideoProcessing\VideoWorkflow::class);
        $settings = \App\Domain\Content\VideoProcessing\VideoSettings::fromInput(['instruction' => 'Показать идею материала за десять секунд', 'text_version' => $text['public_id'], 'image_version' => $variant['public_id']]);
        $id = $workflow->request($ctx, $source, $itemId, $revision, $settings);
        $workflow->run($ctx, $source, $itemId, $id);
        $workflow->choose($ctx, $source, $itemId, $id);
        $workflow->request($ctx, $source, $itemId, $revision, \App\Domain\Content\VideoProcessing\VideoSettings::fromInput(['aspect_ratio' => '16:9', 'duration' => '30']));
        $c->instance(\App\Integrations\Video\VideoProvider::class, new class () implements \App\Integrations\Video\VideoProvider {
            public function name(): string { return 'fake'; }
            public function generate(\App\Integrations\Video\VideoInput $input): \App\Integrations\Video\VideoResult { throw new \RuntimeException('Synthetic failure'); }
        });
        /** @var \App\Domain\Content\VideoProcessing\VideoWorkflow $failure */
        $failure = $c->make(\App\Domain\Content\VideoProcessing\VideoWorkflow::class);
        $id = $failure->request($ctx, $source, $itemId, $revision, \App\Domain\Content\VideoProcessing\VideoSettings::fromInput([]));
        $failure->run($ctx, $source, $itemId, $id);
        echo 'Isolated video UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
