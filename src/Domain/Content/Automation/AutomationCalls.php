<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Durable pre-call cost fence. Lost responses need human reconciliation rather than another paid call. */
final class AutomationCalls
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AutomationGuard $guard)
    {
    }
    public function claimImage(int $workspaceId, int $sourceId, string $jobId, string $operation): bool
    {
        $policy = $this->db->select('SELECT settings_json FROM content_automation_settings WHERE workspace_id=? AND source_id=?', [$workspaceId, $sourceId])[0] ?? null;
        if ($policy !== null) {
            $settings = json_decode((string) $policy['settings_json'], true, 32, JSON_THROW_ON_ERROR);
            // A queued manual request is still manual; only automated photo jobs require the live policy.
            $run = $this->db->select('SELECT r.id FROM content_automation_runs r JOIN source_image_processings p ON p.item_id=r.item_id AND p.workspace_id=r.workspace_id AND p.revision_hash=r.revision_hash AND p.selection_hash=r.selection_hash WHERE p.workspace_id=? AND p.public_id=? AND r.step=? LIMIT 1', [$workspaceId, $jobId, 'images']);
            if ($run !== [] && ($settings['enabled'] !== true || $settings['auto_image_processing'] !== true)) {
                return false;
            }
        }
        if (!$this->guard->allows($operation)) {
            return false;
        }
        $key = hash('sha256', $jobId . ':' . $operation);
        return $this->db->execute('INSERT IGNORE INTO content_automation_calls (workspace_id, operation_key, operation, created_at) VALUES (?, ?, ?, ?)', [$workspaceId, $key, $operation, DbTime::format($this->clock->now())]) === 1;
    }
}
