<?php

declare(strict_types=1);

namespace App\Integrations\Images;

use App\Integrations\ContentProviders\ProviderHttp;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\ContentProviders\SafeDownloads;

/** TinEye v2 reverse lookup; candidate identity is always rechecked by the existing local image verification. */
final class TinEyeImageSearchProvider implements ImageSearchProvider
{
    public function __construct(private readonly ProviderHttp $http, private readonly SafeDownloads $downloads, private readonly string $key)
    {
    }
    public function name(): string
    {
        return 'tineye';
    }
    public function search(string $originalPath): array
    {
        if ($this->key === '') {
            throw new ProviderException('not_configured');
        }
        $size = filesize($originalPath);
        if ($size === false || $size < 1 || $size > 16777216) {
            throw new ProviderException('invalid_image_input');
        }
        $stream = fopen($originalPath, 'rb');
        if ($stream === false) {
            throw new ProviderException('invalid_image_input');
        }
        try {
            $row = $this->http->json('POST', 'https://api.tineye.com/rest/search/?offset=0&limit=5&sort=size&order=desc', ['headers' => ['X-API-Key' => $this->key], 'multipart' => [['name' => 'image_upload', 'contents' => $stream, 'filename' => 'original.jpg']]]);
        } finally {
            fclose($stream);
        }
        if (($row['code'] ?? null) !== 200 || !is_array($row['results'] ?? null) || !is_array($row['results']['matches'] ?? null)) {
            throw new ProviderException('invalid_search_response');
        }
        $candidates = [];
        foreach (array_slice($row['results']['matches'], 0, 5) as $match) {
            if (!is_array($match)) {
                continue;
            }
            $backlinks = $match['backlinks'] ?? [];
            $url = is_array($backlinks) && is_array($backlinks[0] ?? null) ? ($backlinks[0]['url'] ?? null) : null;
            if (!is_string($url)) {
                continue;
            }
            try {
                $bytes = $this->downloads->bytes($url, 16777216, ['image/jpeg', 'image/png', 'image/webp']);
                $parts = parse_url($url);
                if (!is_array($parts) || !isset($parts['host'])) {
                    continue;
                }
                // Signed query strings never reach metadata/UI; bytes are downloaded using the complete URL.
                $provenance = 'https://' . $parts['host'] . ($parts['path'] ?? '/');
                $candidates[] = new ImageCandidate($bytes, $provenance, ['provider' => 'tineye', 'reverse_search_match' => true]);
            } catch (\Throwable) {
                continue;
            }
        }
        return $candidates;
    }
}
