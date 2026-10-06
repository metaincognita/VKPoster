<?php

declare(strict_types=1);

// Synthetic image UI, app_test only. Archives use an isolated ignored storage root, never live Source data.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        $c = $this->app->container();
        $c->instance(\App\Kernel\Config::class, \App\Tests\Support\TestEnv::config(['MEDIA_LOCAL_ROOT' => 'storage/testing/source-image-ui']));
        $c->instance(\App\Integrations\Images\ImageSearchProvider::class, new \App\Integrations\Images\FakeImageSearchProvider([
            new \App\Integrations\Images\ImageCandidate(\App\Tests\Support\SourceImageFixture::bytes(1600), 'https://example.com/original.jpg'),
            new \App\Integrations\Images\ImageCandidate(\App\Tests\Support\SourceImageFixture::bytes(1600, different: true), 'https://example.com/different.jpg'),
        ]));
        [$owner, $workspace] = $this->ownerWithWorkspace('image-ui@example.com', 'Image UI');
        $ctx = $this->contextFor($workspace, $owner);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Image Processing UI', 'telegram', '@image_ui_fixture', true);
        $messages = [];
        foreach ([10, 11] as $id) {
            $messages[] = $this->message($id, 'Тестовый альбом для проверки изображений', '777', ['media' => ['kind' => 'photo', 'telegram_id' => (string) (1000 + $id), 'selected' => ['type' => 'w', 'width' => 640, 'height' => 640, 'bytes' => 10000]]]);
        }
        $this->ingest($source, $messages);
        $item = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing item');
        $c->get(\App\Domain\Source\Selection\SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        $materials = $c->get(\App\Domain\Content\Processing\MaterialRepository::class);
        $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $materials->messages($ctx, $source, (int) $item['id']));
        $workflow = $c->get(\App\Domain\Content\ImageProcessing\ImageWorkflow::class);
        $workflow->request($ctx, $source, (string) $item['public_id'], $revision);
        foreach ($workflow->jobs() as $job) {
            $bytes = \App\Tests\Support\SourceImageFixture::bytes();
            $workflow->accept(array_intersect_key($job, array_flip(['job_id', 'peer_id', 'message_id', 'photo_id'])) + ['sha256' => hash('sha256', $bytes), 'data' => base64_encode($bytes)]);
        }
        echo 'Isolated image UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
