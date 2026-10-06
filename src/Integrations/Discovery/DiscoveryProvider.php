<?php

declare(strict_types=1);

namespace App\Integrations\Discovery;

use App\Domain\ContentDiscovery\DiscoveryItem;
use DateTimeImmutable;

/** Provider-neutral discovery batch; adapters must return permitted summaries, not protected full articles. */
interface DiscoveryProvider
{
    public function name(): string;
    public function sourceType(): string;
    /** @return list<DiscoveryItem> Bounded batch, no persistence or workspace database access. */
    public function discover(DateTimeImmutable $now): array;
}
