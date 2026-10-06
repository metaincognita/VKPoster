<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use DateTimeImmutable;

/** Fixed 100-point budget with explicit null components for unreported metrics; no imputed or AI scores. */
final class TrendScore
{
    /**
     * @param list<array<string,mixed>> $items
     * @return array{score:int,coverage:int,components:array<string,array<string,mixed>>}
     */
    public function calculate(array $items, DateTimeImmutable $now): array
    {
        $published = [];
        $recentSources = [];
        $sources = [];
        $authority = [];
        $engagement = [];
        $growth = [];
        foreach ($items as $item) {
            $sources[(string) $item['source_key']] = true;
            if ($item['published_at'] !== null) {
                $published[] = (new DateTimeImmutable((string) $item['published_at'], new \DateTimeZone('UTC')))->getTimestamp();
            }
            if ($now->getTimestamp() - (new DateTimeImmutable((string) $item['discovered_at'], new \DateTimeZone('UTC')))->getTimestamp() <= 21600) {
                $recentSources[(string) $item['source_key']] = true;
            }
            $meta = json_decode((string) $item['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
            $metrics = json_decode((string) $item['engagement_json'], true, 32, JSON_THROW_ON_ERROR);
            if (isset($meta['authority'])) {
                $authority[(string) $item['source_key']] = (float) $meta['authority'];
            }
            if (isset($metrics['views']) || isset($metrics['reactions']) || isset($metrics['shares'])) {
                $measure = ($metrics['views'] ?? 0) + 5 * ($metrics['reactions'] ?? 0) + 10 * ($metrics['shares'] ?? 0);
                $engagement[] = min(15, 15 * log(1 + $measure) / log(10001));
            }
            if (($metrics['baseline_comparable'] ?? false) === true && ($metrics['baseline_views'] ?? 0) > 0 && isset($metrics['views'], $metrics['window_hours']) && $metrics['window_hours'] > 0) {
                $growth[] = min(10, max(0, 5 * ($metrics['views'] / $metrics['baseline_views'] - 1)));
            }
        }
        $components = [
            'freshness' => ['weight' => 25, 'value' => $published === [] ? null : round(25 * max(0, 1 - max(0, $now->getTimestamp() - max($published)) / 259200), 2), 'reason' => 'Свежесть: линейное снижение за 72 часа по известной дате публикации.'],
            'independent_sources' => ['weight' => 20, 'value' => min(20, max(0, count($sources) - 1) * 5), 'count' => count($sources), 'reason' => 'По 5 баллов за дополнительный независимый source key, максимум 20.'],
            'velocity' => ['weight' => 15, 'value' => min(15, count($recentSources) * 3), 'count' => count($recentSources), 'reason' => 'По 3 балла за новый source key, обнаруженный за 6 часов. Это скорость обнаружения, не измеренная скорость публикаций.'],
            'engagement' => ['weight' => 15, 'value' => $engagement === [] ? null : round(max($engagement), 2), 'known' => count($engagement), 'reason' => 'До 15 баллов: log(1 + views + 5×reactions + 10×shares) / log(10001). Используются только доступные счётчики.'],
            'authority' => ['weight' => 15, 'value' => $authority === [] ? null : round(15 * array_sum($authority) / count($authority), 2), 'known' => count($authority), 'reason' => 'Средняя известная оценка авторитетности provider × 15; это настроенная оценка, не доказательство достоверности.'],
            'relative_growth' => ['weight' => 10, 'value' => $growth === [] ? null : round(max($growth), 2), 'known' => count($growth), 'reason' => '5 × (views / baseline_views − 1), от 0 до 10. Только явно сопоставимый baseline за одинаковое окно.'],
        ];
        $score = 0;
        $coverage = 0;
        foreach ($components as $part) {
            if ($part['value'] !== null) {
                $score += $part['value'];
                $coverage += $part['weight'];
            }
        }
        return ['score' => min(100, max(0, (int) round($score))), 'coverage' => $coverage, 'components' => $components];
    }
}
