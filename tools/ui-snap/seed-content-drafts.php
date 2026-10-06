<?php

declare(strict_types=1);

// Synthetic content export UI fixtures: app_test only, no live material or credentials.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        [$owner, $workspace] = $this->ownerWithWorkspace('draft-ui@example.com', 'Content draft UI');
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $c->get(\App\Domain\Channel\ChannelRepository::class)->connect($ctx, \App\Integrations\Social\Contracts\Platform::Fake, 'draft-ui-only', \App\Domain\Channel\ChannelMode::SharedBot, 'Synthetic destination', null, 'channel', null, ['rights' => ['post' => true, 'edit' => true]], $owner->id);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Content Draft UI', 'telegram', '@draft_ui_fixture', true);
        $this->ingest($source, [$this->message(10, 'Approved synthetic material: original text stays unchanged.')]);
        $item = $this->db->table('source_items')->first() ?? throw new \RuntimeException('Missing material');
        $c->get(\App\Domain\Source\Selection\SelectionService::class)->decide($ctx, $source, (string) $item['public_id'], true);
        $c->get(\App\Domain\ContentDiscovery\ContentDiscovery::class)->refresh($ctx);
        $candidate = $this->db->table('discovery_items')->first() ?? throw new \RuntimeException('Missing candidate');
        $gateway = $c->get(\App\Domain\ContentDiscovery\DiscoveryMaterialGateway::class);
        $id = $gateway->import($ctx, (string) $candidate['public_id']);
        $gateway->decide($ctx, $id, true);
        foreach ([[$source, (string) $item['public_id']], [null, $id]] as [$origin, $materialId]) {
            $materials = $c->get(\App\Domain\Content\Processing\MaterialRepository::class);
            $material = $materials->item($ctx, $origin, $materialId);
            $revision = \App\Domain\Content\Processing\MaterialRepository::revision($material, $materials->messages($ctx, $origin, (int) $material['id']));
            $c->get(\App\Domain\Content\Processing\ContentProcessor::class)->process($ctx, $origin, $materialId, $revision, \App\Domain\Content\Processing\TextSettings::fromInput([]));
            $text = $this->db->table('source_text_processings')->where('item_id', '=', $material['id'])->first() ?? throw new \RuntimeException('Missing text');
            $c->get(\App\Domain\Content\Publishing\ContentDraftService::class)->create($ctx, $origin, $materialId, $revision, (string) $text['public_id']);
        }
        echo 'Isolated content draft UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
