<?php

declare(strict_types=1);

namespace App\Integrations\Discovery;

/** Synthetic discovery adapter; no network or commercial service dependencies. */
final class FakeSocialDiscoveryProvider extends FixtureDiscoveryProvider
{
    public function name(): string
    {
        return 'fake_social';
    }
    public function sourceType(): string
    {
        return 'social';
    }
}
