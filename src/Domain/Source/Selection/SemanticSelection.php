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
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SemanticSelectionProvider $provider, private readonly SemanticPolicy $policy, private readonly \App\Domain\Content\Automation\AutomationPolicies $automation, private readonly \App\Kernel\Queue\Queue $queue)
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
    public function materialLocked(int $workspaceId, ?int $sourceId, array $item, array $messages, SelectionResult $deterministic, bool $automationCall = false): ?array
    {
        $revision = MaterialRepository::revision($item, $messages);
        $candidate = $sourceId === null ? ($this->db->select('SELECT d.title, d.metadata_json FROM discovery_items d JOIN discovery_imports l ON l.discovery_item_id = d.id AND l.workspace_id = d.workspace_id WHERE l.workspace_id = ? AND l.material_id = ?', [$workspaceId, $item['id']])[0] ?? null) : null;
        $input = new SemanticSelectionInput((string) $item['text'], $candidate === null ? null : (string) $candidate['title'], $candidate === null ? ['content_type' => $item['content_type'], 'message_count' => count($messages)] : json_decode((string) $candidate['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $this->settings($workspaceId, $sourceId)['settings']->criteria, $revision);
        return $this->evaluateLocked($workspaceId, $sourceId, 'material', (int) $item['id'], $revision, $input, $deterministic, $automationCall);
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
    private function evaluateLocked(int $workspaceId, ?int $sourceId, string $origin, int $id, string $revision, SemanticSelectionInput $input, SelectionResult $deterministic, bool $automationCall = false): ?array
    {
        return $this->db->transaction(fn (): ?array => $this->prepare($workspaceId, $sourceId, $origin, $id, $revision, $input, $deterministic, $automationCall));
    }

    /** @return array<string,mixed>|null */
    private function prepare(int $workspaceId, ?int $sourceId, string $origin, int $id, string $revision, SemanticSelectionInput $input, SelectionResult $deterministic, bool $automationCall): ?array
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
        if (!$this->automation->semanticAllowed($workspaceId, $sourceId, $automationCall)) {
            return null;
        }
        $now = DbTime::format($this->clock->now());
        $row = ['workspace_id' => $workspaceId, 'scope_key' => self::scope($sourceId), 'origin_type' => $origin, 'origin_id' => $id, 'revision_hash' => $revision, 'deterministic_hash' => $detHash, 'settings_version' => $settings['version'], 'settings_snapshot_json' => json_encode($settings['settings']->snapshot(), JSON_THROW_ON_ERROR), 'provider' => $this->provider->name(), 'status' => 'blocked', 'deterministic_status' => $deterministic->status, 'deterministic_reason' => $deterministic->reason, 'deterministic_rule' => $deterministic->rule, 'final_decision' => $deterministic->status, 'final_reason' => $deterministic->reason, 'created_at' => $now, 'finished_at' => $now];
        if ($deterministic->status === 'approved') {
            $row['status'] = 'pending';
            $row['final_decision'] = 'needs_review';
            $row['final_reason'] = 'Ожидается смысловая оценка.';
            $row['input_json'] = json_encode(['revision' => $input->revision, 'automation_call' => $automationCall], JSON_THROW_ON_ERROR);
        }
        $this->db->table('semantic_selection_evaluations')->insert($row);
        $attemptId = (int) $this->db->lastInsertId();
        if ($row['status'] === 'pending') {
            $this->queue->dispatch(new \App\Jobs\Content\SemanticSelectionJob($attemptId));
        }
        return $this->db->select('SELECT * FROM semantic_selection_evaluations WHERE workspace_id = ? AND id = ?', [$workspaceId, $attemptId])[0];
    }
    /** Claims a durable attempt before network work. An abandoned paid call is never submitted again. */
    public function run(int $attemptId, SelectionService $selection): void
    {
        $row = $this->db->transaction(function () use ($attemptId): ?array {
            $row = $this->db->select('SELECT * FROM semantic_selection_evaluations WHERE id=? FOR UPDATE', [$attemptId])[0] ?? null;
            if ($row === null || !in_array($row['status'], ['pending', 'processing'], true)) {
                return null;
            }
            if ($row['status'] === 'processing') {
                if ($row['started_at'] !== null && $row['started_at'] > DbTime::format($this->clock->now()->modify('-900 seconds'))) {
                    throw new \App\Integrations\ContentProviders\ProviderException('attempt_in_progress', true);
                }
                $this->db->execute("UPDATE semantic_selection_evaluations SET status='failed', error_category='outcome_unknown', error=?, final_decision='needs_review', final_reason=?, finished_at=? WHERE id=? AND status='processing'", ['Исход вызова не подтверждён. Проверьте provider перед повтором.', 'Нужна ручная проверка.', DbTime::format($this->clock->now()), $attemptId]);
                return null;
            }
            $sourceId = str_starts_with((string) $row['scope_key'], 'source:') ? (int) substr((string) $row['scope_key'], 7) : null;
            $input = json_decode((string) ($row['input_json'] ?? '{}'), true, 32, JSON_THROW_ON_ERROR);
            if (!$this->settings((int) $row['workspace_id'], $sourceId)['settings']->enabled || !$this->automation->semanticAllowed((int) $row['workspace_id'], $sourceId, (bool) ($input['automation_call'] ?? false))) {
                $this->db->execute("UPDATE semantic_selection_evaluations SET status='cancelled', final_decision='needs_review', final_reason=?, finished_at=? WHERE id=?", ['Оценка отменена настройками.', DbTime::format($this->clock->now()), $attemptId]);
                return null;
            }
            if (!$this->attemptCurrent($row)) {
                $this->db->execute("UPDATE semantic_selection_evaluations SET status='stale' WHERE id=?", [$attemptId]);
                return null;
            }
            if ($row['origin_type'] === 'material') {
                $manual = $this->db->select('SELECT selection_status, reason FROM source_selection_decisions WHERE workspace_id=? AND item_id=? AND decision_mode=? AND revision_hash=?', [$row['workspace_id'], $row['origin_id'], 'manual', $row['revision_hash']])[0] ?? null;
                if ($manual !== null) {
                    $this->db->execute("UPDATE semantic_selection_evaluations SET status='blocked', final_decision=?, final_reason=?, finished_at=? WHERE id=? AND status='pending'", [$manual['selection_status'], $manual['reason'], DbTime::format($this->clock->now()), $attemptId]);
                    return null;
                }
            }
            $this->db->execute("UPDATE semantic_selection_evaluations SET status='processing', attempt_count=attempt_count+1, started_at=?, error_category=NULL WHERE id=? AND status='pending'", [DbTime::format($this->clock->now()), $attemptId]);
            $row['attempt_count'] = (int) $row['attempt_count'] + 1;
            $row['input'] = $this->loadInput($row);
            return $row;
        });
        if ($row === null) {
            return;
        }
        $values = ['status' => 'failed', 'final_decision' => 'needs_review', 'final_reason' => 'Нужна ручная проверка.', 'error' => 'Смысловая оценка не выполнена. Проверьте материал вручную.'];
        try {
            /** @var SemanticSelectionInput $input */
            $input = $row['input'];
            if (mb_strlen($input->text) > 100000 || $row['provider'] !== $this->provider->name()) {
                throw new \RuntimeException('Semantic input unavailable');
            }
            $result = $this->provider->evaluate($input);
            $final = $this->policy->combine(new SelectionResult((string) $row['deterministic_status'], (string) $row['deterministic_reason'], (string) $row['deterministic_rule']), $result, SemanticSettings::fromInput(json_decode((string) $row['settings_snapshot_json'], true, 32, JSON_THROW_ON_ERROR)));
            $values = ['status' => 'completed', 'decision' => $result->decision, 'score' => $result->score, 'confidence' => $result->confidence, 'reason' => $result->reason, 'matched_criteria_json' => json_encode($result->matchedCriteria, JSON_THROW_ON_ERROR), 'review_flags_json' => json_encode($result->reviewFlags, JSON_THROW_ON_ERROR), 'final_decision' => $final->status, 'final_reason' => $final->reason, 'error' => null];
        } catch (\App\Integrations\ContentProviders\ProviderException $e) {
            if ($e->retryable && $row['attempt_count'] < 5) {
                $this->db->execute("UPDATE semantic_selection_evaluations SET status='pending', started_at=NULL, error_category=? WHERE id=? AND status='processing'", [$e->category, $attemptId]);
                throw $e; // Existing worker applies bounded exponential backoff.
            }
            $values['error_category'] = $e->category;
        } catch (Throwable) {
            $values['error_category'] = 'outcome_unknown';
        }
        if ($this->provider instanceof \App\Integrations\ContentProviders\ProviderMetadata) {
            $values['provider_metadata_json'] = json_encode($this->provider->metadata(), JSON_THROW_ON_ERROR);
        }
        $this->db->transaction(function () use ($row, $values, $attemptId, $selection): void {
            $workspaceId = (int) $row['workspace_id'];
            $sourceId = str_starts_with((string) $row['scope_key'], 'source:') ? (int) substr((string) $row['scope_key'], 7) : null;
            if ($sourceId === null) {
                $this->db->select('SELECT id FROM workspaces WHERE id=? FOR UPDATE', [$workspaceId]);
            } else {
                $this->db->select('SELECT id FROM sources WHERE workspace_id=? AND id=? FOR UPDATE', [$workspaceId, $sourceId]);
            }
            $current = $this->attemptCurrent($row);
            $values['finished_at'] = DbTime::format($this->clock->now());
            if (!$current) {
                $values['status'] = 'stale';
            }
            $this->db->table('semantic_selection_evaluations')->where('id', '=', $attemptId)->where('status', '=', 'processing')->update($values);
            if ($current && $row['origin_type'] === 'material') {
                if ($sourceId === null) {
                    $selection->evaluateDiscoveryMaterialLocked($workspaceId, (int) $row['origin_id']);
                } else {
                    $selection->evaluateLocked($workspaceId, $sourceId, (int) $row['origin_id']);
                }
            }
        });
    }

    /** @param array<string,mixed> $row */
    private function loadInput(array $row): SemanticSelectionInput
    {
        $criteria = SemanticSettings::fromInput(json_decode((string) $row['settings_snapshot_json'], true, 32, JSON_THROW_ON_ERROR))->criteria;
        if ($row['origin_type'] === 'discovery') {
            $item = $this->db->select('SELECT * FROM discovery_items WHERE workspace_id=? AND id=?', [$row['workspace_id'], $row['origin_id']])[0];
            return new SemanticSelectionInput((string) $item['excerpt'], (string) $item['title'], json_decode((string) $item['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $criteria, (string) $row['revision_hash']);
        }
        $item = $this->db->select('SELECT * FROM source_items WHERE workspace_id=? AND id=?', [$row['workspace_id'], $row['origin_id']])[0];
        $candidate = $item['source_id'] === null ? ($this->db->select('SELECT d.* FROM discovery_items d JOIN discovery_imports l ON l.discovery_item_id=d.id AND l.workspace_id=d.workspace_id WHERE l.workspace_id=? AND l.material_id=?', [$row['workspace_id'], $item['id']])[0] ?? null) : null;
        $messages = $this->db->select('SELECT id FROM source_messages WHERE workspace_id=? AND item_id=?', [$row['workspace_id'], $item['id']]);
        return new SemanticSelectionInput((string) $item['text'], $candidate === null ? null : (string) $candidate['title'], $candidate === null ? ['content_type' => $item['content_type'], 'message_count' => count($messages)] : json_decode((string) $candidate['metadata_json'], true, 32, JSON_THROW_ON_ERROR), $criteria, (string) $row['revision_hash']);
    }

    /** @param array<string,mixed> $row */
    private function attemptCurrent(array $row): bool
    {
        $workspaceId = (int) $row['workspace_id'];
        $sourceId = str_starts_with((string) $row['scope_key'], 'source:') ? (int) substr((string) $row['scope_key'], 7) : null;
        if ($this->settings($workspaceId, $sourceId)['version'] !== (int) $row['settings_version']) {
            return false;
        }
        if ($row['origin_type'] === 'discovery') {
            $item = $this->db->select('SELECT * FROM discovery_items WHERE workspace_id=? AND id=?', [$workspaceId, $row['origin_id']])[0] ?? null;
            return $item !== null && self::candidateRevision($item) === $row['revision_hash'];
        }
        $item = $this->db->select('SELECT * FROM source_items WHERE workspace_id=? AND source_id <=> ? AND id=?', [$workspaceId, $sourceId, $row['origin_id']])[0] ?? null;
        $messages = $this->db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id=? AND item_id=? ORDER BY message_id', [$workspaceId, $row['origin_id']]);
        if ($sourceId !== null && $item !== null) {
            $source = $this->db->select('SELECT connection_version FROM sources WHERE workspace_id=? AND id=?', [$workspaceId, $sourceId])[0] ?? null;
            if ($source === null || (int) $source['connection_version'] !== (int) $item['connection_version']) {
                return false;
            }
        }
        return $item !== null && MaterialRepository::revision($item, $messages) === $row['revision_hash'];
    }

    private static function deterministicHash(SelectionResult $result): string
    {
        return hash('sha256', json_encode([$result->status, $result->reason, $result->rule], JSON_THROW_ON_ERROR));
    }

}
