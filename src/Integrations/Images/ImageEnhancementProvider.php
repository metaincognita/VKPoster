<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Replaceable enhancement boundary without dependence on a model or AI vendor. */
interface ImageEnhancementProvider
{
    /** Returns separate encoded bytes, or null when enhancement is unavailable. */
    public function enhance(string $originalPath): ?string;
    public function name(): string;
}
