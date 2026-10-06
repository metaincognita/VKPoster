<?php

declare(strict_types=1);

namespace App\Integrations\Discovery;

/** Replaceable registry; business code does not depend on concrete Telegram/news/social services. */
final readonly class DiscoveryProviders
{
    /** @param list<DiscoveryProvider> $providers */
    public function __construct(public array $providers)
    {
    }
}
