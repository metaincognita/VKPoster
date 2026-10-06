<?php

declare(strict_types=1);

namespace App\Domain\Content\Operations;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use App\Support\Heartbeat;

/** Read-only operational summary for the existing console; counts and timestamps only, never content, secrets or provider payloads. */
final class ContentStatus
{
    public function __construct(private readonly Connection $db, private readonly \Redis $redis, private readonly Clock $clock, private readonly Heartbeat $heartbeat)
    {
    }
    /** @return array<string,mixed> */
    public function report(): array
    {
        $counts = [];
        foreach ($this->db->select('SELECT status, COUNT(*) AS n FROM content_automation_runs GROUP BY status') as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        $reader = $this->heartbeat->at('telegram-reader');
        $age = $reader === null ? null : max(0, $this->clock->now()->getTimestamp() - $reader->getTimestamp());
        $providers = [];
        foreach (['openai', 'replicate', 'tineye'] as $name) {
            try {
                $providers[$name] = ['errors' => (int) $this->redis->get('content-provider:' . $name . ':errors'), 'circuit_open' => $this->redis->exists('content-provider:' . $name . ':open') > 0];
            } catch (\Throwable) {
                $providers[$name] = ['unavailable' => true];
            }
        }
        return [
            'automation' => $counts,
            'last_success_at' => $this->db->select("SELECT MAX(finished_at) AS at FROM content_automation_runs WHERE status='completed'")[0]['at'],
            'last_processing_success_at' => $this->db->select("SELECT MAX(at) AS at FROM (SELECT MAX(finished_at) AS at FROM source_text_processings WHERE status='completed' UNION ALL SELECT MAX(finished_at) AS at FROM source_image_processings WHERE status='completed' UNION ALL SELECT MAX(finished_at) AS at FROM source_video_generations WHERE status='completed') successes")[0]['at'],
            'discovery' => $this->db->select('SELECT status, COUNT(*) AS n, MAX(updated_at) AS last_at FROM content_automation_runs WHERE item_id IS NULL GROUP BY status'),
            'reader' => ['state' => $age === null ? 'unknown' : ($age <= 180 ? 'ok' : 'stale'), 'age_seconds' => $age],
            'providers' => $providers,
            'queue' => ['pending' => $this->db->table('jobs')->count(), 'failed' => $this->db->table('failed_jobs')->count(), 'abandoned' => (int) $this->db->select('SELECT COUNT(*) AS n FROM jobs WHERE reserved_at<?', [DbTime::format($this->clock->now()->modify('-15 minutes'))])[0]['n']],
        ];
    }
}
