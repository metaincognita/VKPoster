<?php

declare(strict_types=1);

// Synthetic UI fixture only; never authorizes Telegram or changes live workspace/consent.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        [$owner, $workspace] = $this->ownerWithWorkspace('semantic-ui@example.com', 'Semantic UI');
        $ctx = $this->contextFor($workspace, $owner);
        $c = $this->app->container();
        $selection = $c->get(\App\Domain\Source\Selection\SelectionService::class);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Смысловой отбор', 'telegram', '@semantic_fixture', true);
        $selection->saveRules($ctx, $source, \App\Domain\Source\Selection\SelectionRules::fromInput([]));
        $selection->saveSemantic($ctx, $source, new \App\Domain\Source\Selection\SemanticSettings(true, 'Брать важные новости об AI и технологиях, телескопах. Исключать рекламу. Неясные события проверять вручную.', 70, 0.8, 'review'));
        $this->ingest($source, [$this->message(10, 'AI и технологии: тестовая новость о новом исследовании.')]);
        $this->ingest($source, [$this->message(11, 'Реклама AI: демонстрационная публикация.')]);
        $item = $this->db->table('source_items')->where('source_id', '=', $source->id)->orderBy('id')->first() ?? throw new \RuntimeException('Missing material');
        $selection->decide($ctx, $source, (string) $item['public_id'], false);
        $c->get(\App\Domain\ContentDiscovery\ContentDiscovery::class)->refresh($ctx);
        $selection->saveSemantic($ctx, null, new \App\Domain\Source\Selection\SemanticSettings(true, 'Телескоп и AI: брать важные новости технологий, исключать рекламу.', 70, 0.8, 'review'));
        $candidate = $c->get(\App\Domain\ContentDiscovery\DiscoveryRepository::class)->clusters($ctx)[0]['items'][0];
        $c->get(\App\Domain\ContentDiscovery\DiscoveryMaterialGateway::class)->import($ctx, (string) $candidate['public_id']);
        echo 'Isolated semantic UI fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
