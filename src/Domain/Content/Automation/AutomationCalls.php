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
        return $this->db->transaction(function () use ($workspaceId, $key, $operation): bool {
            $row = $this->db->select('SELECT * FROM content_automation_calls WHERE workspace_id=? AND operation_key=? FOR UPDATE', [$workspaceId, $key])[0] ?? null;
            $now = DbTime::format($this->clock->now());
            if ($row === null) {
                $this->db->execute("INSERT INTO content_automation_calls (workspace_id, operation_key, operation, created_at, status) VALUES (?, ?, ?, ?, 'uncertain')", [$workspaceId, $key, $operation, $now]);
                return true;
            }
            if ($row['status'] === 'retryable' && $row['available_at'] <= $now && (int) $row['attempts'] < 5) {
                $this->db->execute("UPDATE content_automation_calls SET status='uncertain', attempts=attempts+1 WHERE id=?", [$row['id']]);
                return true;
            }
            if ($row['status'] === 'retryable' && (int) $row['attempts'] < 5) {
                throw new \App\Integrations\ContentProviders\ProviderException('retry_backoff', true);
            }
            return false;
        });
    }

    /** @return list<array<string,mixed>>|null Archived successful variants, reusable without provider replay. */
    public function imageResult(int $workspaceId, string $jobId, string $operation): ?array
    {
        $row = $this->db->select("SELECT result_json FROM content_automation_calls WHERE workspace_id=? AND operation_key=? AND status='success'", [$workspaceId, hash('sha256', $jobId . ':' . $operation)])[0] ?? null;
        return $row === null ? null : json_decode((string) $row['result_json'], true, 32, JSON_THROW_ON_ERROR);
    }
    /** @param list<array<string,mixed>> $variants */
    public function imageSuccess(int $workspaceId, string $jobId, string $operation, array $variants): void
    {
        $this->db->execute("UPDATE content_automation_calls SET status='success', result_json=?, error_category=NULL WHERE workspace_id=? AND operation_key=? AND status='uncertain'", [json_encode($variants, JSON_THROW_ON_ERROR), $workspaceId, hash('sha256', $jobId . ':' . $operation)]);
    }
    public function imageFailure(int $workspaceId, string $jobId, string $operation, \App\Integrations\ContentProviders\ProviderException $error): void
    {
        $this->db->execute("UPDATE content_automation_calls SET status=?, error_category=?, available_at=? WHERE workspace_id=? AND operation_key=? AND status='uncertain'", [$error->retryable ? 'retryable' : 'uncertain', $error->category, DbTime::format($this->clock->now()->modify('+60 seconds')), $workspaceId, hash('sha256', $jobId . ':' . $operation)]);
    }
}
