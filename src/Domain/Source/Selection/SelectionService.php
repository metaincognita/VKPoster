<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

use App\Domain\Audit\AuditLog;
use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;

/** Atomic selection boundary. Source locking serializes imports, rule changes and manual overrides. */
final class SelectionService
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly SelectionEngine $engine, private readonly AuditLog $audit, private readonly SemanticSelection $semantic)
    {
    }

    public function rules(WorkspaceContext $context, Source $source): ?SelectionRules
    {
        $row = $this->db->select('SELECT rules_json FROM source_selection_rules WHERE workspace_id = ? AND source_id = ?', [$context->workspaceId, $source->id])[0] ?? null;
        return $row === null ? null : $this->decodeRules((string) $row['rules_json']);
    }

    /** Saves a new version and re-evaluates all automatic decisions; manual decisions remain untouched. */
    public function saveRules(WorkspaceContext $context, Source $source, SelectionRules $rules): void
    {
        $this->db->transaction(function () use ($context, $source, $rules): void {
            $this->lock($context, $source);
            $now = DbTime::format($this->clock->now());
            $this->db->execute(
                'INSERT INTO source_selection_rules (workspace_id, source_id, version, rules_json, updated_by, created_at, updated_at)
                VALUES (?, ?, 1, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE version=version+1, rules_json=VALUES(rules_json), updated_by=VALUES(updated_by), updated_at=VALUES(updated_at)',
                [$context->workspaceId, $source->id, json_encode($rules->values, JSON_THROW_ON_ERROR), $context->userId, $now, $now]
            );
            // Keyset batches bound memory; all decisions see the same rules version inside this transaction.
            $cursor = 0;
            do {
                $items = $this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND source_id = ? AND id > ? ORDER BY id LIMIT 200', [$context->workspaceId, $source->id, $cursor]);
                foreach ($items as $item) {
                    $cursor = (int) $item['id'];
                    $this->evaluateLocked($context->workspaceId, $source->id, $cursor);
                }
            } while (count($items) === 200);
            $this->audit->record('source.rules_updated', $context->userId, 'source', $source->publicId, [], $context->workspaceId);
        });
    }

    /** Manual approval/rejection survives future edits and automatic rule recalculations. */
    public function decide(WorkspaceContext $context, Source $source, string $itemPublicId, bool $approve): void
    {
        $this->db->transaction(function () use ($context, $source, $itemPublicId, $approve): void {
            $this->lock($context, $source);
            $item = $this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND source_id = ? AND public_id = ?', [$context->workspaceId, $source->id, $itemPublicId])[0] ?? throw new HttpException(404, 'Not found');
            $this->write($context->workspaceId, $source->id, (int) $item['id'], new SelectionResult($approve ? 'approved' : 'rejected', $approve ? 'Принято вручную.' : 'Отклонено вручную.', $approve ? 'manual.approve' : 'manual.reject'), 'manual', $context->userId);
            $this->audit->record($approve ? 'source.item_approved' : 'source.item_rejected', $context->userId, 'source_item', $itemPublicId, [], $context->workspaceId);
        });
    }

    /** Internal ingress only: caller must hold Source FOR UPDATE in the event's transaction before ACK. */
    public function evaluateLocked(int $workspaceId, int $sourceId, int $itemId, bool $automationCall = false): void
    {
        $prior = $this->db->select('SELECT decision_mode, revision_hash FROM source_selection_decisions WHERE workspace_id = ? AND source_id = ? AND item_id = ?', [$workspaceId, $sourceId, $itemId])[0] ?? null;
        $item = $this->db->select('SELECT * FROM source_items WHERE workspace_id = ? AND source_id = ? AND id = ?', [$workspaceId, $sourceId, $itemId])[0] ?? throw new HttpException(404, 'Not found');
        $rawMessages = $this->db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id = ? AND source_id = ? AND item_id = ? ORDER BY message_id', [$workspaceId, $sourceId, $itemId]);
        $result = $this->deterministic($workspaceId, $sourceId, $item, $rawMessages);
        if ($prior !== null && $prior['decision_mode'] === 'manual' && ($prior['revision_hash'] ?? null) === \App\Domain\Content\Processing\MaterialRepository::revision($item, $rawMessages)) {
            return;
        }
        $evaluation = $this->semantic->materialLocked($workspaceId, $sourceId, $item, $rawMessages, $result, $automationCall);
        if ($prior !== null && $prior['decision_mode'] === 'manual' && ($prior['revision_hash'] ?? null) === \App\Domain\Content\Processing\MaterialRepository::revision($item, $rawMessages)) {
            return;
        }
        if ($evaluation !== null && $evaluation['status'] !== 'blocked') {
            $result = new SelectionResult((string) $evaluation['final_decision'], (string) $evaluation['final_reason'], 'semantic.' . $evaluation['status']);
        }
        $this->write($workspaceId, $sourceId, $itemId, $result, 'automatic', null);
    }

    /** Read-only deterministic layer for material UI; caller has already authorized the material.
     * @param array<string,mixed> $item
     * @param list<array<string,mixed>> $rawMessages */
    public function deterministic(int $workspaceId, ?int $sourceId, array $item, array $rawMessages): SelectionResult
    {
        if ($sourceId === null) {
            return $this->engine->evaluate(SelectionRules::fromInput([]), (string) $item['text'], (string) $item['content_type'], $rawMessages);
        }
        $messages = [];
        foreach ($rawMessages as $row) {
            $metadata = json_decode((string) $row['metadata_json'], true, 32, JSON_THROW_ON_ERROR);
            $messages[] = ['entities' => json_decode((string) $row['entities_json'], true, 32, JSON_THROW_ON_ERROR), 'forward' => $metadata['forward'] ?? null, 'forward_known' => $metadata['forward_known'] ?? false];
        }
        $row = $this->db->select('SELECT rules_json FROM source_selection_rules WHERE workspace_id = ? AND source_id = ?', [$workspaceId, $sourceId])[0] ?? null;
        return $this->engine->evaluate($row === null ? null : $this->decodeRules((string) $row['rules_json']), (string) $item['text'], (string) $item['content_type'], $messages);
    }

    /** Save optional semantic policy in the existing selection boundary, retaining every manual override. */
    public function saveSemantic(WorkspaceContext $ctx, ?Source $source, SemanticSettings $settings): void
    {
        $this->db->transaction(function () use ($ctx, $source, $settings): void {
            if ($source !== null) {
                $this->lock($ctx, $source);
            } elseif ($this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]) === []) {
                throw new HttpException(404, 'Not found');
            }
            $this->semantic->saveLocked($ctx, $source?->id, $settings);
            foreach ($this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND source_id <=> ? ORDER BY id', [$ctx->workspaceId, $source?->id]) as $item) {
                if ($source !== null) {
                    $this->evaluateLocked($ctx->workspaceId, $source->id, (int) $item['id']);
                } else {
                    $this->evaluateDiscoveryMaterialLocked($ctx->workspaceId, (int) $item['id']);
                }
            }
            if ($source === null) {
                $this->evaluateCandidatesLocked($ctx->workspaceId);
            }
            $this->audit->record('selection.semantic_settings_updated', $ctx->userId, $source === null ? null : 'source', $source?->publicId, ['enabled' => $settings->enabled], $ctx->workspaceId);
        });
    }

    /** Caller holds workspace lock; only materials registered through Discovery are eligible. */
    public function evaluateDiscoveryMaterialLocked(int $workspaceId, int $itemId, bool $automatic = false): void
    {
        $item = $this->db->select('SELECT i.* FROM source_items i JOIN discovery_imports l ON l.material_id = i.id AND l.workspace_id = i.workspace_id WHERE i.workspace_id = ? AND i.id = ? AND i.source_id IS NULL', [$workspaceId, $itemId])[0] ?? throw new HttpException(404, 'Not found');
        $deterministic = $this->deterministic($workspaceId, null, $item, []);
        $prior = $this->db->select('SELECT decision_mode, revision_hash FROM source_selection_decisions WHERE workspace_id = ? AND item_id = ? AND source_id IS NULL', [$workspaceId, $itemId])[0] ?? null;
        if (($prior['decision_mode'] ?? '') === 'manual' && ($prior['revision_hash'] ?? null) === \App\Domain\Content\Processing\MaterialRepository::revision($item, [])) {
            return;
        }
        $evaluation = $this->semantic->materialLocked($workspaceId, null, $item, [], $deterministic, $automatic);
        $result = $evaluation === null ? ($automatic ? $deterministic : new SelectionResult('needs_review', 'Материал из радара ожидает ручного решения.', 'discovery.import')) : new SelectionResult((string) $evaluation['final_decision'], (string) $evaluation['final_reason'], 'semantic.' . $evaluation['status']);
        $this->write($workspaceId, null, $itemId, $result, 'automatic', null);
    }

    /** Internal discovery command; latest content is evaluated once per revision and settings version. */
    public function evaluateCandidatesLocked(int $workspaceId): void
    {
        foreach ($this->db->select('SELECT * FROM discovery_items WHERE workspace_id = ? AND status <> ? ORDER BY id', [$workspaceId, 'ignored']) as $item) {
            $deterministic = $this->engine->evaluate(SelectionRules::fromInput([]), $item['title'] . '\n' . $item['excerpt'], 'text', []);
            $this->semantic->candidateLocked($workspaceId, $item, $deterministic);
        }
    }

    private function lock(WorkspaceContext $context, Source $source): void
    {
        if ($this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$context->workspaceId, $source->id]) === []) {
            throw new HttpException(404, 'Not found');
        }
    }

    private function write(int $workspaceId, ?int $sourceId, int $itemId, SelectionResult $result, string $mode, ?int $actor): void
    {
        $rules = $this->db->select('SELECT version, rules_json FROM source_selection_rules WHERE workspace_id = ? AND source_id <=> ?', [$workspaceId, $sourceId])[0] ?? null;
        $item = $this->db->select('SELECT * FROM source_items WHERE workspace_id=? AND id=?', [$workspaceId, $itemId])[0];
        $messages = $this->db->select('SELECT message_id, text, entities_json, media_json, metadata_json, published_at, edited_at, revision_hash FROM source_messages WHERE workspace_id=? AND item_id=? ORDER BY message_id', [$workspaceId, $itemId]);
        $revision = \App\Domain\Content\Processing\MaterialRepository::revision($item, $messages);
        $now = DbTime::format($this->clock->now());
        $this->db->execute(
            'INSERT INTO source_selection_decisions (workspace_id, source_id, item_id, selection_status, decision_mode, reason, matched_rule, rules_version, rules_snapshot_json, decided_by, created_at, updated_at, revision_hash)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE selection_status=VALUES(selection_status), decision_mode=VALUES(decision_mode), reason=VALUES(reason), matched_rule=VALUES(matched_rule), rules_version=VALUES(rules_version), rules_snapshot_json=VALUES(rules_snapshot_json), decided_by=VALUES(decided_by), updated_at=VALUES(updated_at), revision_hash=VALUES(revision_hash)',
            [$workspaceId, $sourceId, $itemId, $result->status, $mode, $result->reason, $result->rule, $rules === null ? null : (int) $rules['version'], $rules === null ? null : (string) $rules['rules_json'], $actor, $now, $now, $revision]
        );
    }

    private function decodeRules(string $json): SelectionRules
    {
        /** @var array<string, list<string>|string> $values */
        $values = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        return SelectionRules::fromSnapshot($values);
    }
}
