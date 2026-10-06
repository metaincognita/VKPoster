<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Exercises separate persistence only: a byte-for-byte copy, no claim of improved image quality. */
final readonly class FakeImageEnhancementProvider implements ImageEnhancementProvider
{
    public function __construct(private bool $enabled = true)
    {
    }
    public function enhance(string $originalPath): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $bytes = file_get_contents($originalPath);
        return $bytes === false ? throw new \RuntimeException('Image read failed') : $bytes;
    }
    public function name(): string
    {
        return 'fake';
    }
}
