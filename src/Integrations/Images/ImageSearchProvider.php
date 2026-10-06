<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Replaceable reverse-image search; adapters return bytes and provenance, never automatically selected images. */
interface ImageSearchProvider
{
    /** @return list<ImageCandidate> */
    public function search(string $originalPath): array;
    public function name(): string;
}
