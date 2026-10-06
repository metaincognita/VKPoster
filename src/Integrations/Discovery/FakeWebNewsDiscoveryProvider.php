<?php

declare(strict_types=1);

namespace App\Integrations\Discovery;

/** Synthetic discovery adapter; no network or commercial service dependencies. */
final class FakeWebNewsDiscoveryProvider extends FixtureDiscoveryProvider
{
    public function name(): string
    {
        return 'fake_web';
    }
    public function sourceType(): string
    {
        return 'web';
    }
}
