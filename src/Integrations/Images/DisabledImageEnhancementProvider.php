<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Explicit production-safe opt-out; no external work and no fabricated enhancement. */
final class DisabledImageEnhancementProvider implements ImageEnhancementProvider
{
    public function name(): string
    {
        return 'disabled';
    }
    public function enhance(string $originalPath): ?string
    {
        return null;
    }
}
