<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

/** Deterministic title/excerpt token overlap within 36 hours; numerical disagreement prevents merging different events. */
final class DiscoveryClustering
{
    /** @return list<string> */
    public function tokens(string $text): array
    {
        $words = explode(' ', DiscoveryIdentity::title($text));
        $stop = ['the', 'and', 'with', 'from', 'for', 'news', 'today', 'this', 'that', 'как', 'что', 'для', 'это', 'новости', 'сегодня', 'также', 'после', 'перед'];
        $result = array_values(array_unique(array_filter($words, static fn (string $w): bool => (mb_strlen($w) >= 3 || ctype_digit($w)) && !in_array($w, $stop, true))));
        sort($result);
        return array_slice($result, 0, 80);
    }
    /**
     * @param list<string> $left
     * @param list<string> $right
     */
    public function similarity(array $left, array $right, int $hours): float
    {
        if ($hours > 36 || $left === [] || $right === []) {
            return 0;
        }
        $numbersLeft = array_values(array_filter($left, 'ctype_digit'));
        $numbersRight = array_values(array_filter($right, 'ctype_digit'));
        if ($numbersLeft !== [] && $numbersRight !== [] && $numbersLeft !== $numbersRight) {
            return 0;
        }
        $intersection = array_intersect($left, $right);
        return count($intersection) < 3 ? 0 : count($intersection) / count(array_unique(array_merge($left, $right)));
    }
}
