<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** Typed output manifest. The fake output explicitly has no video asset. Real adapters must archive files privately. */
final readonly class VideoResult
{
    public function __construct(public bool $demonstration, public ?string $storageKey = null)
    {
        if ((!$demonstration && ($storageKey === null || $storageKey === '')) || ($demonstration && $storageKey !== null) || ($storageKey !== null && (strlen($storageKey) > 512 || preg_match('~^source-videos/[A-Za-z0-9/_-]+$~D', $storageKey) !== 1 || str_contains($storageKey, '..')))) {
            throw new \InvalidArgumentException('Invalid video result');
        }
    }
    /** @return array{demonstration:bool,storage_key:?string} */
    public function snapshot(): array
    {
        return ['demonstration' => $this->demonstration, 'storage_key' => $this->storageKey];
    }
}
