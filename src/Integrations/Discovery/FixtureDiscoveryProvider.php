<?php

declare(strict_types=1);

namespace App\Integrations\Discovery;

use App\Domain\ContentDiscovery\DiscoveryItem;
use DateTimeImmutable;
use RuntimeException;

/** Local synthetic fixtures shared by three fake adapters. No network, scraping or credentials; production disabled. */
abstract class FixtureDiscoveryProvider implements DiscoveryProvider
{
    public function __construct(private readonly bool $allowed = true)
    {
    }
    public function discover(DateTimeImmutable $now): array
    {
        if (!$this->allowed) {
            throw new RuntimeException('Discovery provider unavailable');
        }
        $path = dirname(__DIR__, 3) . '/resources/discovery/fake/' . $this->sourceType() . '.json';
        $rows = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new DiscoveryItem($this->name(), $this->sourceType(), (string) $row['source_key'], (string) $row['source_name'], (string) $row['url'], $row['external_id'] ?? null, (string) $row['title'], (string) $row['excerpt'], isset($row['hours_ago']) ? $now->modify('-' . (int) $row['hours_ago'] . ' hours') : null, $row['metadata'] ?? [], $row['engagement'] ?? [], $row['language'] ?? null);
        }
        return $result;
    }
}
