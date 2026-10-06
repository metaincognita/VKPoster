<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use InvalidArgumentException;

/** Conservative URL identities and source-scoped text fingerprints; independent publishers remain separate evidence. */
final class DiscoveryIdentity
{
    public static function url(string $url): string
    {
        $p = parse_url($url);
        if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || (isset($p['port']) && $p['port'] !== 443) || strlen($url) > 2048 || preg_match('/[\x00-\x20\\\\]/', $url) === 1 || preg_match('/^[a-z0-9.-]+$/iD', $p['host']) !== 1 || !str_contains($p['host'], '.') || filter_var($p['host'], FILTER_VALIDATE_IP) !== false) {
            throw new InvalidArgumentException('Invalid canonical URL');
        }
        $query = [];
        foreach (explode('&', $p['query'] ?? '') as $part) {
            if ($part === '') {
                continue;
            }
            $key = strtolower(urldecode(explode('=', $part, 2)[0]));
            if (!str_starts_with($key, 'utm_') && !in_array($key, ['fbclid', 'gclid'], true)) {
                $query[] = $part;
            }
        }
        sort($query);
        $path = rtrim($p['path'] ?? '', '/');
        return 'https://' . strtolower($p['host']) . ($path === '' ? '/' : $path) . ($query === [] ? '' : '?' . implode('&', $query));
    }
    public static function title(string $title): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($title)) ?? '');
    }
    /** @return array<string,string> */
    public static function keys(DiscoveryItem $item): array
    {
        $title = self::title($item->title);
        $day = $item->publishedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') ?? 'unknown';
        $text = $title . '|' . self::title($item->excerpt);
        $keys = ['url' => hash('sha256', self::url($item->url)), 'fingerprint' => hash('sha256', $item->sourceKey . '|' . $day . '|' . (mb_strlen($text) >= 20 ? $text : self::url($item->url)))];
        // Short generic titles are not safe identities. Full title matches are publisher/day scoped, not cross-publisher dedupe.
        if (mb_strlen($title) >= 20 && count(explode(' ', $title)) >= 3) {
            $keys['title'] = hash('sha256', $item->sourceKey . '|' . $day . '|' . $title);
        }
        if ($item->externalId !== null) {
            $keys['external'] = hash('sha256', $item->provider . '|' . $item->sourceKey . '|' . $item->externalId);
        }
        return $keys;
    }
}
