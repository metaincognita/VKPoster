<?php

declare(strict_types=1);

// Discovery UI fixtures only; never touches live Source data or credentials.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        [$owner, $workspace] = $this->ownerWithWorkspace('radar-ui@example.com', 'Radar UI');
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $c->get(\App\Domain\ContentDiscovery\ContentDiscovery::class)->refresh($ctx);
        $item = $c->get(\App\Domain\ContentDiscovery\DiscoveryRepository::class)->clusters($ctx)[0]['items'][0];
        $gateway = $c->get(\App\Domain\ContentDiscovery\DiscoveryMaterialGateway::class);
        $id = $gateway->import($ctx, (string) $item['public_id']);
        $gateway->decide($ctx, $id, true);
        $material = $c->get(\App\Domain\Content\Processing\MaterialRepository::class)->item($ctx, null, $id);
        $revision = \App\Domain\Content\Processing\MaterialRepository::revision($material, []);
        $c->get(\App\Domain\Content\Processing\ContentProcessor::class)->process($ctx, null, $id, $revision, \App\Domain\Content\Processing\TextSettings::fromInput(['mode' => 'rewrite']));
        echo 'Isolated radar UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
