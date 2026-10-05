<?php

declare(strict_types=1);

// Executed only in the isolated app_test database before the cross-language HTTP contract test.
require '/var/www/html/vendor/autoload.php';

$fixture = new class ('fixture') extends \App\Tests\Support\WorkspaceTestCase {
    public function fixture(): void
    {
        $this->setUp();
        [$owner, $workspace] = $this->ownerWithWorkspace('reader-contract@example.com');
        $context = $this->contextFor($workspace, $owner);
        $service = $this->app->container()->get(\App\Domain\Source\SourceService::class);
        $service->create($context, 'Contract A', 'telegram', '@reader_contract_a', true);
        $service->create($context, 'Contract B', 'telegram', '@reader_contract_b', true);
        $service->create($context, 'Contract disabled', 'telegram', '@reader_contract_disabled');
    }
};
$fixture->fixture();
