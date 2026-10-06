<?php

declare(strict_types=1);

namespace App\Integrations\Images;

/** Provider returns bounded bytes, never an arbitrary filesystem path. Network adapters must enforce SSRF protection. */
final readonly class ImageCandidate
{
    public function __construct(public string $bytes, public string $sourceUrl)
    {
        $url = parse_url($sourceUrl);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || ($url['host'] ?? '') === '' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || strlen($sourceUrl) > 2048) {
            throw new \InvalidArgumentException('Invalid image provenance');
        }
    }
}
