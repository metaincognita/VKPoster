<?php

declare(strict_types=1);

namespace App\Domain\Admin;

use App\Kernel\Database\Connection;
use App\Support\DbTime;
use DateTimeImmutable;

/**
 * Operational read model of the back office: how publishing is going (totals, success rate, errors by kind and network, delay, the
 * worst channels and workspaces), whether a network is failing right now (error share by hour), storage use and AI use.
 * Reads the base tables for a bounded period, so it answers about "now" even when the daily aggregation has not run yet.
 */
final class OperationalStats
{
    private const LATENCY_SAMPLE = 20000;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param DateTimeImmutable $to exclusive end
     * @return array{
     *     platforms: array<string, array{sent: int, failed: int, unknown: int, waiting: int, total: int, success: ?float}>,
     *     total: array{sent: int, failed: int, unknown: int, waiting: int, total: int, success: ?float},
     *     errors: array<string, array<string, int>>,
     *     latency: array<string, array{p50: float, p95: float, count: int}>,
     *     channels: list<array{title: string, platform: string, workspace: string, workspace_public_id: string, failed: int, total: int}>,
     *     workspaces: list<array{name: string, public_id: string, failed: int, total: int}>
     * }
     */
    public function publications(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $range = [DbTime::format($from), DbTime::format($to)];
        $empty = ['sent' => 0, 'failed' => 0, 'unknown' => 0, 'waiting' => 0, 'total' => 0, 'success' => null];
        $platforms = [];
        $total = $empty;
        foreach ($this->db->select(
            'SELECT v.platform, p.status, COUNT(*) AS c FROM publications p JOIN post_variants v ON v.id = p.variant_id WHERE p.due_at >= ? AND p.due_at < ? GROUP BY v.platform, p.status',
            $range,
        ) as $row) {
            $key = match ((string) $row['status']) {
                'sent' => 'sent',
                'failed' => 'failed',
                'unknown' => 'unknown',
                'cancelled' => null,
                default => 'waiting',
            };
            if ($key === null) {
                continue;
            }
            $platform = (string) $row['platform'];
            $platforms[$platform] ??= $empty;
            $platforms[$platform][$key] += (int) $row['c'];
            $platforms[$platform]['total'] += (int) $row['c'];
            $total[$key] += (int) $row['c'];
            $total['total'] += (int) $row['c'];
        }
        $rate = static fn (array $r): ?float => $r['sent'] + $r['failed'] + $r['unknown'] > 0 ? round($r['sent'] / ($r['sent'] + $r['failed'] + $r['unknown']) * 100, 1) : null;
        foreach ($platforms as $name => $row) {
            $platforms[$name]['success'] = $rate($row);
        }
        ksort($platforms);
        $total['success'] = $rate($total);

        $errors = [];
        foreach ($this->db->select(
            "SELECT v.platform, COALESCE(NULLIF(p.error_code, ''), 'unknown') AS code, COUNT(*) AS c FROM publications p JOIN post_variants v ON v.id = p.variant_id "
            . "WHERE p.status IN ('failed', 'unknown') AND p.due_at >= ? AND p.due_at < ? GROUP BY v.platform, code ORDER BY c DESC",
            $range,
        ) as $row) {
            $errors[(string) $row['code']][(string) $row['platform']] = (int) $row['c'];
        }

        $samples = [];
        foreach ($this->db->select(
            "SELECT v.platform, TIMESTAMPDIFF(MICROSECOND, p.due_at, p.sent_at) / 1000000 AS seconds FROM publications p JOIN post_variants v ON v.id = p.variant_id "
            . "WHERE p.status = 'sent' AND p.sent_at IS NOT NULL AND p.due_at >= ? AND p.due_at < ? ORDER BY p.id DESC LIMIT " . self::LATENCY_SAMPLE,
            $range,
        ) as $row) {
            $seconds = max(0.0, (float) $row['seconds']);
            $samples[(string) $row['platform']][] = $seconds;
            $samples['all'][] = $seconds;
        }
        $latency = [];
        foreach ($samples as $name => $values) {
            sort($values);
            $latency[$name] = ['p50' => self::percentile($values, 50), 'p95' => self::percentile($values, 95), 'count' => count($values)];
        }
        ksort($latency);

        $channels = [];
        foreach ($this->db->select(
            "SELECT COALESCE(c.title, v.channel_name) AS title, v.platform, w.name AS workspace, w.public_id AS workspace_public_id, "
            . "SUM(p.status IN ('failed', 'unknown')) AS failed, COUNT(*) AS total FROM publications p JOIN post_variants v ON v.id = p.variant_id "
            . 'LEFT JOIN channels c ON c.id = p.channel_id JOIN workspaces w ON w.id = p.workspace_id WHERE p.due_at >= ? AND p.due_at < ? '
            . "AND p.status <> 'cancelled' GROUP BY p.channel_id, v.channel_name, v.platform, w.id, w.name, w.public_id, c.title HAVING failed > 0 ORDER BY failed DESC, total DESC LIMIT 10",
            $range,
        ) as $row) {
            $channels[] = ['title' => (string) $row['title'], 'platform' => (string) $row['platform'], 'workspace' => (string) $row['workspace'], 'workspace_public_id' => (string) $row['workspace_public_id'], 'failed' => (int) $row['failed'], 'total' => (int) $row['total']];
        }
        $workspaces = [];
        foreach ($this->db->select(
            "SELECT w.name, w.public_id, SUM(p.status IN ('failed', 'unknown')) AS failed, COUNT(*) AS total FROM publications p JOIN workspaces w ON w.id = p.workspace_id "
            . "WHERE p.due_at >= ? AND p.due_at < ? AND p.status <> 'cancelled' GROUP BY w.id, w.name, w.public_id HAVING failed > 0 ORDER BY failed DESC, total DESC LIMIT 10",
            $range,
        ) as $row) {
            $workspaces[] = ['name' => (string) $row['name'], 'public_id' => (string) $row['public_id'], 'failed' => (int) $row['failed'], 'total' => (int) $row['total']];
        }

        return ['platforms' => $platforms, 'total' => $total, 'errors' => $errors, 'latency' => $latency, 'channels' => $channels, 'workspaces' => $workspaces];
    }

    /**
     * Share of failed publishing attempts per hour and network over the last hours: an early sign that a network is down.
     *
     * @return array{hours: list<string>, platforms: array<string, list<?float>>, attempts: array<string, list<int>>, rate_limited: array<string, list<int>>}
     */
    public function errorsByHour(DateTimeImmutable $now, int $hours = 48): array
    {
        $start = $now->setTime((int) $now->format('G'), 0)->modify('-' . ($hours - 1) . ' hours');
        $rows = $this->db->select(
            "SELECT v.platform, DATE_FORMAT(a.finished_at, '%Y-%m-%d %H:00') AS hour, COUNT(*) AS total, "
            . "SUM(a.outcome IN ('temporary', 'permanent', 'auth', 'rate_limited', 'failed', 'unknown')) AS bad, SUM(a.outcome = 'rate_limited') AS limited "
            . 'FROM publication_attempts a JOIN publications p ON p.id = a.publication_id JOIN post_variants v ON v.id = p.variant_id '
            . "WHERE a.finished_at >= ? AND a.outcome IN ('sent', 'temporary', 'permanent', 'auth', 'rate_limited', 'failed', 'unknown') GROUP BY v.platform, hour",
            [DbTime::format($start)],
        );
        $labels = [];
        for ($h = 0; $h < $hours; $h++) {
            $labels[] = $start->modify('+' . $h . ' hours')->format('Y-m-d H:00');
        }
        $index = array_flip($labels);
        $platforms = [];
        $attempts = [];
        $limited = [];
        foreach ($rows as $row) {
            $platform = (string) $row['platform'];
            $i = $index[(string) $row['hour']] ?? null;
            if ($i === null) {
                continue;
            }
            $platforms[$platform] ??= array_fill(0, $hours, null);
            $attempts[$platform] ??= array_fill(0, $hours, 0);
            $limited[$platform] ??= array_fill(0, $hours, 0);
            $platforms[$platform][$i] = (int) $row['total'] > 0 ? round((int) $row['bad'] / (int) $row['total'] * 100, 1) : null;
            $attempts[$platform][$i] = (int) $row['total'];
            $limited[$platform][$i] = (int) $row['limited'];
        }
        ksort($platforms);
        ksort($attempts);
        ksort($limited);

        return ['hours' => $labels, 'platforms' => $platforms, 'attempts' => $attempts, 'rate_limited' => $limited];
    }

    /**
     * Library volume: the total and the biggest workspaces.
     *
     * @return array{total_bytes: int, files: int, top: list<array{name: string, public_id: string, bytes: int, files: int}>}
     */
    public function storage(): array
    {
        $total = $this->db->select('SELECT COALESCE(SUM(size), 0) AS bytes, COUNT(*) AS files FROM media')[0] ?? [];
        $top = [];
        foreach ($this->db->select(
            'SELECT w.name, w.public_id, SUM(m.size) AS bytes, COUNT(*) AS files FROM media m JOIN workspaces w ON w.id = m.workspace_id GROUP BY w.id, w.name, w.public_id ORDER BY bytes DESC LIMIT 10',
        ) as $row) {
            $top[] = ['name' => (string) $row['name'], 'public_id' => (string) $row['public_id'], 'bytes' => (int) $row['bytes'], 'files' => (int) $row['files']];
        }

        return ['total_bytes' => (int) ($total['bytes'] ?? 0), 'files' => (int) ($total['files'] ?? 0), 'top' => $top];
    }

    /**
     * What the AI assistant used (events `ai_used` with `credits` and `cost` in kopecks of the provider's bill) in the period.
     * The assistant arrives in a later stage; until then there are no events and the numbers are zero.
     *
     * @return array{requests: int, credits: int, cost: int}
     */
    public function ai(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $row = $this->db->select(
            "SELECT COUNT(*) AS requests, COALESCE(SUM(JSON_EXTRACT(props_json, '$.credits')), 0) AS credits, COALESCE(SUM(JSON_EXTRACT(props_json, '$.cost')), 0) AS cost "
            . "FROM analytics_events WHERE name = 'ai_used' AND occurred_at >= ? AND occurred_at < ?",
            [DbTime::format($from), DbTime::format($to)],
        )[0] ?? [];

        return ['requests' => (int) ($row['requests'] ?? 0), 'credits' => (int) ($row['credits'] ?? 0), 'cost' => (int) ($row['cost'] ?? 0)];
    }

    /** @return array<string, int> Text processing outcomes only, without source content or instructions. */
    public function sourceText(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = ['processing' => 0, 'completed' => 0, 'failed' => 0, 'stale' => 0];
        foreach ($this->db->select('SELECT status, COUNT(*) AS c FROM source_text_processings WHERE created_at >= ? AND created_at < ? GROUP BY status', [DbTime::format($from), DbTime::format($to)]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Derived post counts only; the ordinary post editor remains the intervention boundary.
     * @return array<string,int>
     */
    public function contentDrafts(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = [];
        foreach ($this->db->select('SELECT COALESCE(p.status, ?) AS status, COUNT(*) AS c FROM content_post_origins o LEFT JOIN posts p ON p.id = o.post_id AND p.workspace_id = o.workspace_id WHERE o.created_at >= ? AND o.created_at < ? GROUP BY status', ['deleted', DbTime::format($from), DbTime::format($to)]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /** @return array<string,int> Semantic counts only; no post contents, criteria, provider payloads or secrets. */
    public function semanticSelection(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $counts = ['completed' => 0, 'failed' => 0, 'blocked' => 0];
        foreach ($this->db->select('SELECT status, COUNT(*) AS c FROM semantic_selection_evaluations WHERE created_at >= ? AND created_at < ? GROUP BY status', [DbTime::format($from), DbTime::format($to)]) as $row) {
            $counts[(string) $row['status']] = (int) $row['c'];
        }
        return $counts;
    }

    /**
     * Nearest-rank percentile of an ascending list (0 for an empty one).
     *
     * @param list<float> $sorted
     */
    public static function percentile(array $sorted, int $percent): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $rank = max(1, (int) ceil($percent / 100 * count($sorted)));

        return round($sorted[min($rank, count($sorted)) - 1], 1);
    }
}
