<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Settings\Settings;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Fills `metrics_daily`: one row per day, metric and breakdown. A day is recomputed from scratch (its rows are deleted and written again in
 * one transaction), so running it twice, or over a day that is still going, gives the same result as running it once at the end of that day.
 * The scheduler recomputes the last few days every hour; `backfill()` rebuilds any period.
 *
 * Days are UTC days. Amounts are minor units (kopecks) in the currency named in the breakdown.
 *
 * Metrics and their breakdown (`dim`):
 *  - `signups` (source), `verified`, `channels_connected`, `first_posts`, `activated`: people and workspaces reaching each step that day
 *  - `trials_started`, `first_paid`: trial starts; workspaces paying for the first time
 *  - `revenue` (provider:plan:currency), `refunds` (provider:currency), `payments_failed` (provider): money, from the ledger
 *  - `mrr`, `paying` (plan:currency): snapshot at the end of the day
 *  - `mrr_new`, `mrr_expansion`, `mrr_contraction`, `mrr_churn`, `paying_new`, `paying_churned` (currency): movements against the day before
 *  - `dau`, `wau`, `mau`: distinct people active that day, the last 7 days, the last 30 days
 *  - `pubs_sent`, `pubs_failed` (platform): publications that went out or finally failed
 */
final class MetricsAggregator
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly MrrCalculator $mrr,
        private readonly Settings $settings,
    ) {
    }

    /**
     * Recompute the last `$days` days including today. Returns the number of rows written.
     */
    public function recent(int $days = 3): int
    {
        $today = $this->today();
        $written = $this->range($today->modify('-' . max(0, $days - 1) . ' days'), $today);
        $this->settings->set('metrics.refreshed_at', $this->clock->now()->getTimestamp(), null);

        return $written;
    }

    /**
     * Recompute every day of a period (both ends included).
     */
    public function range(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $written = 0;
        for ($day = $this->midnight($from); $day <= $this->midnight($to); $day = $day->modify('+1 day')) {
            $written += $this->day($day);
        }

        return $written;
    }

    /**
     * Recompute one UTC day. Returns the number of rows written.
     */
    public function day(DateTimeImmutable $day): int
    {
        $day = $this->midnight($day);
        $from = DbTime::format($day);
        $to = DbTime::format($day->modify('+1 day'));
        $rows = [];
        $add = static function (string $metric, string $dim, int $value) use (&$rows): void {
            $key = $metric . "\0" . mb_substr($dim, 0, 80);
            $rows[$key] = ($rows[$key] ?? 0) + $value;
        };

        // People and workspaces reaching each step.
        foreach ($this->db->select(
            "SELECT t.src, COUNT(*) AS c FROM (SELECT LEFT(COALESCE(NULLIF(a.utm_source, ''), NULLIF(a.referrer, ''), 'direct'), 60) AS src FROM users u LEFT JOIN user_attribution a ON a.user_id = u.id "
            . 'WHERE u.created_at >= ? AND u.created_at < ?) t GROUP BY t.src',
            [$from, $to],
        ) as $row) {
            $add('signups', (string) $row['src'], (int) $row['c']);
        }
        $add('verified', '', $this->count('SELECT COUNT(*) AS c FROM users WHERE email_verified_at >= ? AND email_verified_at < ?', [$from, $to]));
        $add('channels_connected', '', $this->firstPer('channels', 'created_at', null, $from, $to));
        $add('first_posts', '', $this->firstPer('posts', 'created_at', "status <> 'draft'", $from, $to));
        $add('activated', '', $this->firstPer('publications', 'sent_at', "status = 'sent'", $from, $to));
        $add('trials_started', '', $this->count("SELECT COUNT(*) AS c FROM audit_log WHERE action = 'billing.trial_started' AND created_at >= ? AND created_at < ?", [$from, $to]));
        $add('first_paid', '', $this->count(
            "SELECT COUNT(*) AS c FROM (SELECT workspace_id, MIN(paid_at) AS first_paid FROM invoices WHERE status = 'paid' GROUP BY workspace_id) f WHERE f.first_paid >= ? AND f.first_paid < ?",
            [$from, $to],
        ));

        // Separate text-processing outcomes; no content, credentials or fake AI billing.
        foreach ($this->db->select('SELECT status, COUNT(*) AS c FROM source_text_processings WHERE created_at >= ? AND created_at < ? GROUP BY status', [$from, $to]) as $row) {
            $add('source_text_attempts', (string) $row['status'], (int) $row['c']);
        }

        // Money, from the ledger (the provider's side of every movement).
        foreach ($this->db->select(
            "SELECT SUBSTRING(a.code, 10) AS provider, pl.code AS plan, e.currency, SUM(e.amount) AS total FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id "
            . 'JOIN payments pay ON pay.public_id = e.ref_id JOIN invoices i ON i.id = pay.invoice_id JOIN plans pl ON pl.id = i.plan_id '
            . "WHERE e.ref_type = 'payment' AND a.code LIKE 'provider:%' AND e.created_at >= ? AND e.created_at < ? GROUP BY a.code, pl.code, e.currency",
            [$from, $to],
        ) as $row) {
            $add('revenue', $row['provider'] . ':' . $row['plan'] . ':' . $row['currency'], (int) $row['total']);
        }
        foreach ($this->db->select(
            "SELECT SUBSTRING(a.code, 10) AS provider, e.currency, SUM(-e.amount) AS total FROM ledger_entries e JOIN ledger_accounts a ON a.id = e.account_id "
            . "WHERE e.ref_type = 'refund' AND a.code LIKE 'provider:%' AND e.created_at >= ? AND e.created_at < ? GROUP BY a.code, e.currency",
            [$from, $to],
        ) as $row) {
            $add('refunds', $row['provider'] . ':' . $row['currency'], (int) $row['total']);
        }
        foreach ($this->db->select(
            "SELECT provider, COUNT(*) AS c FROM payments WHERE status = 'failed' AND updated_at >= ? AND updated_at < ? GROUP BY provider",
            [$from, $to],
        ) as $row) {
            $add('payments_failed', (string) $row['provider'], (int) $row['c']);
        }

        // MRR at the end of the day and what changed since the day before.
        $before = $this->mrr->at($day->modify('-1 microsecond'));
        $after = $this->mrr->at($day->modify('+1 day')->modify('-1 microsecond'));
        foreach ($after as $workspaceId => $now) {
            $add('mrr', $now['plan'] . ':' . $now['currency'], $now['mrr']);
            $add('paying', $now['plan'] . ':' . $now['currency'], 1);
            $was = $before[$workspaceId] ?? null;
            if ($was === null || $was['currency'] !== $now['currency']) {
                $add('mrr_new', $now['currency'], $now['mrr']);
                $add('paying_new', $now['currency'], 1);
            } elseif ($now['mrr'] > $was['mrr']) {
                $add('mrr_expansion', $now['currency'], $now['mrr'] - $was['mrr']);
            } elseif ($now['mrr'] < $was['mrr']) {
                $add('mrr_contraction', $now['currency'], $was['mrr'] - $now['mrr']);
            }
        }
        foreach ($before as $workspaceId => $was) {
            $now = $after[$workspaceId] ?? null;
            if ($now === null || $now['currency'] !== $was['currency']) {
                $add('mrr_churn', $was['currency'], $was['mrr']);
                $add('paying_churned', $was['currency'], 1);
            }
        }

        // Activity.
        $date = $day->format('Y-m-d');
        $add('dau', '', $this->count('SELECT COUNT(*) AS c FROM user_activity_days WHERE day = ?', [$date]));
        $add('wau', '', $this->count('SELECT COUNT(DISTINCT user_id) AS c FROM user_activity_days WHERE day BETWEEN ? AND ?', [$day->modify('-6 days')->format('Y-m-d'), $date]));
        $add('mau', '', $this->count('SELECT COUNT(DISTINCT user_id) AS c FROM user_activity_days WHERE day BETWEEN ? AND ?', [$day->modify('-29 days')->format('Y-m-d'), $date]));

        // Publishing, by network (the variant keeps the network even after the channel is gone).
        foreach ($this->db->select(
            "SELECT v.platform, p.status, COUNT(*) AS c FROM publications p JOIN post_variants v ON v.id = p.variant_id "
            . "WHERE ((p.status = 'sent' AND p.sent_at >= ? AND p.sent_at < ?) OR (p.status = 'failed' AND p.updated_at >= ? AND p.updated_at < ?)) GROUP BY v.platform, p.status",
            [$from, $to, $from, $to],
        ) as $row) {
            $add($row['status'] === 'sent' ? 'pubs_sent' : 'pubs_failed', (string) $row['platform'], (int) $row['c']);
        }

        $this->db->transaction(function (Connection $db) use ($date, $rows): void {
            $db->execute('DELETE FROM metrics_daily WHERE day = ?', [$date]);
            foreach ($rows as $key => $value) {
                [$metric, $dim] = explode("\0", $key, 2);
                $db->execute('INSERT INTO metrics_daily (day, metric, dim, value) VALUES (?, ?, ?, ?)', [$date, $metric, $dim, $value]);
            }
        });

        return count($rows);
    }

    private function today(): DateTimeImmutable
    {
        return $this->midnight($this->clock->now());
    }

    private function midnight(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->setTime(0, 0);
    }

    /**
     * @param list<int|string> $bindings
     */
    private function count(string $sql, array $bindings): int
    {
        return (int) ($this->db->select($sql, $bindings)[0]['c'] ?? 0);
    }

    /**
     * How many workspaces had their first row of a table (the earliest `$column`) inside the day.
     */
    private function firstPer(string $table, string $column, ?string $where, string $from, string $to): int
    {
        return $this->count(
            'SELECT COUNT(*) AS c FROM (SELECT workspace_id, MIN(' . $column . ') AS first_at FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where) . ' GROUP BY workspace_id) t WHERE t.first_at >= ? AND t.first_at < ?',
            [$from, $to],
        );
    }
}
