<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Offline fixture provider: no web requests or invented search results. */
final readonly class FakeImageSearchProvider implements ImageSearchProvider
{
    /** @param list<ImageCandidate> $candidates */
    public function __construct(private array $candidates = [])
    {
    }
    public function search(string $originalPath): array
    {
        return $this->candidates;
    }
    public function name(): string
    {
        return 'fake';
    }
}
