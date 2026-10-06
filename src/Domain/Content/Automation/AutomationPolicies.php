<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Domain\Audit\AuditLog;
use App\Domain\Source\Source;
use App\Domain\Source\Selection\SemanticSelection;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Workspace-scoped automation configuration and safe technical run summaries. */
final class AutomationPolicies
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly AuditLog $audit)
    {
    }
    /** @return array<string,mixed> */
    public function settings(int $workspaceId, ?int $sourceId): array
    {
        $row = $this->db->select('SELECT * FROM content_automation_settings WHERE workspace_id = ? AND scope_key = ?', [$workspaceId, SemanticSelection::scope($sourceId)])[0] ?? null;
        return $row === null ? AutomationSettings::defaults() : json_decode((string) $row['settings_json'], true, 32, JSON_THROW_ON_ERROR);
    }
    /** @param array<string,mixed> $input */
    public function save(WorkspaceContext $ctx, ?Source $source, array $input): void
    {
        $settings = AutomationSettings::validate($input);
        $this->db->transaction(function () use ($ctx, $source, $settings): void {
            if ($source !== null) {
                $this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $source->id]);
            } else {
                $this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]);
            }
            $now = DbTime::format($this->clock->now());
            $this->db->execute('INSERT INTO content_automation_settings (workspace_id, scope_key, source_id, settings_json, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE version=version+1, settings_json=VALUES(settings_json), next_discovery_at=NULL, updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)', [$ctx->workspaceId, SemanticSelection::scope($source?->id), $source?->id, json_encode($settings, JSON_THROW_ON_ERROR), $ctx->userId, $now, $now]);
            $this->audit->record('content.automation_settings_updated', $ctx->userId, $source === null ? null : 'source', $source?->publicId, [], $ctx->workspaceId);
        });
    }
    /** @return array<string,mixed>|null */
    public function latest(int $workspaceId, ?int $sourceId): ?array
    {
        return $this->db->select('SELECT r.status, r.step, r.last_successful_step, r.error, r.created_at, r.updated_at FROM content_automation_runs r JOIN content_automation_settings s ON s.id = r.settings_id AND s.workspace_id = r.workspace_id WHERE r.workspace_id = ? AND s.scope_key = ? ORDER BY r.id DESC LIMIT 1', [$workspaceId, SemanticSelection::scope($sourceId)])[0] ?? null;
    }
    /** Existing selection stays unchanged without a policy; automation owns semantic opt-in when configured. */
    public function semanticAllowed(int $workspaceId, ?int $sourceId, bool $automationCall = false): bool
    {
        $row = $this->db->select('SELECT settings_json FROM content_automation_settings WHERE workspace_id = ? AND scope_key = ?', [$workspaceId, SemanticSelection::scope($sourceId)])[0] ?? null;
        if ($row === null) {
            return true;
        }
        $v = json_decode((string) $row['settings_json'], true, 32, JSON_THROW_ON_ERROR);
        return $automationCall && $v['enabled'] === true && $v['auto_selection'] === true && $v['semantic_selection'] === true;
    }
}
