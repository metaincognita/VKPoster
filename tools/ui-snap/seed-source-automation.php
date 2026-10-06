<?php

declare(strict_types=1);

// Disposable automation UI fixture, app_test only; never uses live Telegram or real providers.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\SourceSelectionTestCase {
    public function fixture(): void
    {
        $this->setUp();
        if ($this->db->select('SELECT DATABASE() AS name')[0]['name'] !== 'app_test') {
            throw new \RuntimeException('Test database required');
        }
        $c = $this->app->container();
        [$owner, $workspace] = $this->ownerWithWorkspace('automation-ui@example.com', 'Automation UI');
        $ctx = $this->contextFor($workspace, $owner);
        $source = $c->get(\App\Domain\Source\SourceService::class)->create($ctx, 'Automation UI', 'telegram', '@automation_ui_fixture', true);
        $c->get(\App\Domain\Source\Selection\SelectionService::class)->saveRules($ctx, $source, \App\Domain\Source\Selection\SelectionRules::fromInput([]));
        $policies = $c->get(\App\Domain\Content\Automation\AutomationPolicies::class);
        $policies->save($ctx, $source, ['enabled' => true, 'auto_selection' => true, 'auto_text_processing' => true, 'manual_review_fallback' => true]);
        $this->ingest($source, [$this->message(10, 'Синтетический материал: автоматическая обработка завершена, черновик ожидает ручного решения.')]);
        $policies->save($ctx, null, ['enabled' => true, 'discovery_enabled' => true, 'auto_selection' => true, 'auto_text_processing' => true, 'manual_review_fallback' => true, 'provider_types' => ['web'], 'candidate_limit' => 1]);
        for ($i = 0; $i < 2; ++$i) {
            $c->get(\App\Domain\Content\Automation\Automation::class)->tick();
            while ($c->get(\App\Kernel\Queue\Worker::class)->runNext()) {
            }
        }
        echo 'Isolated Source and Radar automation fixture ready', PHP_EOL;
    }
};
$fixture->fixture();
