<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Selection\SemanticSelectionInput;
use App\Integrations\Selection\SemanticSelectionProvider;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use Throwable;

/** Optional layer of existing Selection: immutable revision/policy history, safe failures and no source-content mutation. */
final class SemanticSelection
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SemanticSelectionProvider $provider, private readonly SemanticPolicy $policy)
    {
    }
    public static function scope(?int $sourceId): string
    {
        return $sourceId === null ? 'radar' : 'source:' . $sourceId;
    }
    /**
     * @return array{version:int,settings:SemanticSettings} */
    public function settings(int $workspaceId, ?int $sourceId): array
    {
        $row = $this->db->select('SELECT version, settings_json FROM semantic_selection_settings WHERE workspace_id = ? AND scope_key = ?', [$workspaceId, self::scope($sourceId)])[0] ?? null;
        return ['version' => $row === null ? 0 : (int) $row['version'], 'settings' => SemanticSettings::fromInput($row === null ? [] : json_decode((string) $row['settings_json'], true, 32, JSON_THROW_ON_ERROR))];
    }
    /** Internal command: caller authorizes and locks Source or workspace before recalculating automatic decisions. */
    public function saveLocked(WorkspaceContext $ctx, ?int $sourceId, SemanticSettings $settings): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->execute('INSERT INTO semantic_selection_settings (workspace_id, scope_key, version, settings_json, updated_by, created_at, updated_at) VALUES (?, ?, 1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE version=version+1, settings_json=VALUES(settings_json), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)', [$ctx->workspaceId, self::scope($sourceId), json_encode($settings->snapshot(), JSON_THROW_ON_ERROR), $ctx->userId, $now, $now]);
    }
    /**
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $messages
     * @return array<string,mixed>|null */
    public function materialLocked(int $workspaceId, ?int $sourceId, array $item, array $messages, SelectionResult $deterministic): ?array
    {
        $revision = MaterialRepository::revision($item, $messages);
        $candidate = $sourceId === null ? ($this->db->select('SELECT d.title, d.metadata_json FROM discovery_items d JOIN discovery_imports l ON l.discovery_item_id = d.id AND l.workspace_id = d.workspace_id WHERE l.workspace_id = ? AND l.material_id = ?', [$workspaceId, $item['id']])[0] ?? null) : null;
        $input = new SemanticSelectionInput((string) $item['text'], $candidate === null ? null : (string) $candidate['title'], $candidate === null ? ['content_type' => $item['content_type'], 'message_count' => count($messages)] : json_decode((string) $candidate['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $this->settings($workspaceId, $sourceId)['settings']->criteria, $revision);
        return $this->evaluateLocked($workspaceId, $sourceId, 'material', (int) $item['id'], $revision, $input, $deterministic);
    }
    /** Internal discovery command: workspace lock held, independently ranked candidate, never modifies Trend Score.
     * @param array<string,mixed> $item */
    public function candidateLocked(int $workspaceId, array $item, SelectionResult $deterministic): void
    {
        $revision = self::candidateRevision($item);
        $settings = $this->settings($workspaceId, null)['settings'];
        $input = new SemanticSelectionInput((string) $item['excerpt'], (string) $item['title'], json_decode((string) $item['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $settings->criteria, $revision);
        $this->evaluateLocked($workspaceId, null, 'discovery', (int) $item['id'], $revision, $input, $deterministic);
    }
    /**
     * @param array<string,mixed> $item */
    public static function candidateRevision(array $item): string
    {
        // Popularity updates do not invalidate content relevance; title/excerpt/author changes do.
        return hash('sha256', json_encode([$item['title'], $item['excerpt'], $item['canonical_url'], $item['metadata_json'], $item['language']], JSON_THROW_ON_ERROR));
    }
    /**
     * @return list<array<string,mixed>> */
    public function history(int $workspaceId, string $origin, int $id): array
    {
        return $this->db->select('SELECT * FROM semantic_selection_evaluations WHERE workspace_id = ? AND origin_type = ? AND origin_id = ? ORDER BY id DESC LIMIT 30', [$workspaceId, $origin, $id]);
    }
    /**
     * @return array<string,mixed>|null */
    public function current(int $workspaceId, ?int $sourceId, string $origin, int $id, string $revision, ?SelectionResult $deterministic = null): ?array
    {
        $settings = $this->settings($workspaceId, $sourceId);
        if (!$settings['settings']->enabled) {
            return null;
        }
        $bindings = [$workspaceId, self::scope($sourceId), $origin, $id, $revision, $settings['version']];
        $sql = 'SELECT * FROM semantic_selection_evaluations WHERE workspace_id = ? AND scope_key = ? AND origin_type = ? AND origin_id = ? AND revision_hash = ? AND settings_version = ?';
        if ($deterministic !== null) {
            $sql .= ' AND deterministic_hash = ?';
            $bindings[] = self::deterministicHash($deterministic);
        }
        $row = $this->db->select($sql . ' ORDER BY id DESC LIMIT 1', $bindings)[0] ?? null;
        if ($row !== null) {
            $row['matched_criteria'] = json_decode((string) ($row['matched_criteria_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
            $row['review_flags'] = json_decode((string) ($row['review_flags_json'] ?? '[]'), true, 32, JSON_THROW_ON_ERROR);
        }
        return $row;
    }
    /**
     * @return array<string,mixed>|null */
    private function evaluateLocked(int $workspaceId, ?int $sourceId, string $origin, int $id, string $revision, SemanticSelectionInput $input, SelectionResult $deterministic): ?array
    {
        $settings = $this->settings($workspaceId, $sourceId);
        if (!$settings['settings']->enabled) {
            return null;
        }
        $detHash = self::deterministicHash($deterministic);
        $prior = $this->db->select('SELECT * FROM semantic_selection_evaluations WHERE workspace_id = ? AND scope_key = ? AND origin_type = ? AND origin_id = ? AND revision_hash = ? AND deterministic_hash = ? AND settings_version = ?', [$workspaceId, self::scope($sourceId), $origin, $id, $revision, $detHash, $settings['version']])[0] ?? null;
        if ($prior !== null) {
            return $prior;
        }
        $now = DbTime::format($this->clock->now());
        $row = ['workspace_id' => $workspaceId, 'scope_key' => self::scope($sourceId), 'origin_type' => $origin, 'origin_id' => $id, 'revision_hash' => $revision, 'deterministic_hash' => $detHash, 'settings_version' => $settings['version'], 'settings_snapshot_json' => json_encode($settings['settings']->snapshot(), JSON_THROW_ON_ERROR), 'provider' => $this->provider->name(), 'status' => 'blocked', 'deterministic_status' => $deterministic->status, 'deterministic_reason' => $deterministic->reason, 'deterministic_rule' => $deterministic->rule, 'final_decision' => $deterministic->status, 'final_reason' => $deterministic->reason, 'created_at' => $now, 'finished_at' => $now];
        if ($deterministic->status === 'approved') {
            try {
                if (mb_strlen($input->text) > 100000) {
                    throw new \RuntimeException('Material exceeds semantic input limit');
                }
                $result = $this->provider->evaluate($input);
                $final = $this->policy->combine($deterministic, $result, $settings['settings']);
                $row += ['decision' => $result->decision, 'score' => $result->score, 'confidence' => $result->confidence, 'reason' => $result->reason, 'matched_criteria_json' => json_encode($result->matchedCriteria, JSON_THROW_ON_ERROR), 'review_flags_json' => json_encode($result->reviewFlags, JSON_THROW_ON_ERROR)];
                $row['final_decision'] = $final->status;
                $row['status'] = 'completed';
                // Persist policy explanation separately from raw provider reason.
                $row['deterministic_reason'] = $deterministic->reason;
                $row['final_reason'] = $final->reason;
            } catch (Throwable) {
                $row['status'] = 'failed';
                $row['final_decision'] = 'needs_review';
                $row['final_reason'] = 'Смысловая оценка не выполнена. Нужна ручная проверка.';
                $row['error'] = 'Смысловая оценка не выполнена. Проверьте материал вручную или сохраните настройки для новой попытки.';
            }
        }
        $this->db->table('semantic_selection_evaluations')->insert($row);
        return $this->db->select('SELECT * FROM semantic_selection_evaluations WHERE workspace_id = ? AND id = ?', [$workspaceId, (int) $this->db->lastInsertId()])[0];
    }
    private static function deterministicHash(SelectionResult $result): string
    {
        return hash('sha256', json_encode([$result->status, $result->reason, $result->rule], JSON_THROW_ON_ERROR));
    }

}
