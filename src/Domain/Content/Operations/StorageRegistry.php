<?php

declare(strict_types=1);

namespace App\Domain\Content\Operations;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Register immutable content objects before writing, so a crash before workflow commit leaves a reclaimable orphan. */
final class StorageRegistry
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }
    public function track(string $key): void
    {
        if (!str_starts_with($key, 'source-images/') && !str_starts_with($key, 'source-videos/')) {
            throw new \InvalidArgumentException('Invalid content object');
        }
        $this->db->execute('INSERT INTO content_storage_objects (storage_key, created_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE deleted_at=NULL, created_at=VALUES(created_at)', [$key, DbTime::format($this->clock->now())]);
    }
}
