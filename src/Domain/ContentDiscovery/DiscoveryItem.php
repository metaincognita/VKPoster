<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use DateTimeImmutable;
use InvalidArgumentException;

/** Validated public metadata only: never a protected full article or arbitrary provider payload. */
final readonly class DiscoveryItem
{
    /**
     * @param array<string,mixed> $metadata Source identity, author and optional authority (0..1).
     * @param array<string,mixed> $engagement Optional measured counters and comparable baseline.
     */
    public function __construct(public string $provider, public string $sourceType, public string $sourceKey, public string $sourceName, public string $url, public ?string $externalId, public string $title, public string $excerpt, public ?DateTimeImmutable $publishedAt, public array $metadata = [], public array $engagement = [], public ?string $language = null)
    {
        if (preg_match('/^[a-z0-9_-]{1,32}$/D', $provider) !== 1 || !in_array($sourceType, ['telegram', 'web', 'social'], true) || $sourceKey === '' || strlen($sourceKey) > 128 || preg_match('/^[a-z0-9_.:-]+$/D', $sourceKey) !== 1 || trim($sourceName) === '' || mb_strlen($sourceName) > 255 || trim($title) === '' || mb_strlen($title) > 512 || mb_strlen($excerpt) > 2000 || ($externalId !== null && ($externalId === '' || mb_strlen($externalId) > 255)) || ($language !== null && preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2,4})?$/D', $language) !== 1)) {
            throw new InvalidArgumentException('Invalid discovery metadata');
        }
        foreach ([$sourceName, $title, $excerpt, $externalId ?? ''] as $text) {
            if (!mb_check_encoding($text, 'UTF-8')) {
                throw new InvalidArgumentException('Invalid discovery encoding');
            }
        }
        DiscoveryIdentity::url($url);
        foreach ($metadata as $key => $value) {
            if (!in_array($key, ['author', 'authority', 'authority_basis'], true) || ($key === 'authority' ? !is_numeric($value) || !is_finite((float) $value) || (float) $value < 0 || (float) $value > 1 : !is_string($value) || mb_strlen($value) > 255)) {
                throw new InvalidArgumentException('Invalid source metadata');
            }
        }
        foreach ($engagement as $key => $value) {
            if (!in_array($key, ['views', 'reactions', 'shares', 'baseline_views', 'baseline_comparable', 'window_hours'], true) || ($key === 'baseline_comparable' ? !is_bool($value) : !is_int($value) || $value < 0 || $value > 1000000000000)) {
                throw new InvalidArgumentException('Invalid engagement metadata');
            }
        }
    }
}
