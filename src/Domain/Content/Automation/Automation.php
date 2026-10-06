<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Domain\Content\ImageProcessing\ImageWorkflow;
use App\Domain\Content\Processing\ContentProcessor;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Content\Publishing\ContentDraftService;
use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Content\VideoProcessing\VideoWorkflow;
use App\Domain\ContentDiscovery\ContentDiscovery;
use App\Domain\ContentDiscovery\DiscoveryMaterialGateway;
use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Source;
use App\Domain\Source\SourceRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceRepository;
use App\Jobs\Content\AutomationJob;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Kernel\Queue\Queue;
use App\Support\Clock;
use App\Support\DbTime;
use Throwable;

/** Durable coordinator on the existing Queue: checkpoints, current revision guards, safe restart and draft-only export. */
final class Automation
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly Queue $queue, private readonly WorkspaceRepository $workspaces, private readonly SourceRepository $sources, private readonly MaterialRepository $materials, private readonly SelectionService $selection, private readonly ContentProcessor $text, private readonly ImageWorkflow $images, private readonly VideoWorkflow $videos, private readonly ContentDraftService $drafts, private readonly ContentDiscovery $discovery, private readonly DiscoveryMaterialGateway $gateway, private readonly AutomationGuard $guard, private readonly \App\Kernel\Config $operationsConfig)
    {
    }

    /** Existing minute scheduler discovers work and reclaims lost enqueues, bounded to 100 materials per policy/tick. */
    public function tick(): void
    {
        foreach ($this->db->select('SELECT p.* FROM content_automation_settings p LEFT JOIN sources s ON s.id=p.source_id AND s.workspace_id=p.workspace_id WHERE p.source_id IS NULL OR s.enabled=1 ORDER BY p.id') as $policy) {
            $settings = json_decode((string) $policy['settings_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!($settings['enabled'] === true)) {
                continue;
            }
            try {
                [$ctx, $source] = $this->scope($policy);
            } catch (HttpException) {
                continue;
            }
            $this->db->transaction(function () use ($ctx, $source, $policy, $settings): void {
                $fresh = $this->db->select('SELECT * FROM content_automation_settings WHERE workspace_id=? AND id=? FOR UPDATE', [$ctx->workspaceId, $policy['id']])[0];
                if ((int) $fresh['version'] !== (int) $policy['version']) {
                    return;
                }
                $now = DbTime::format($this->clock->now());
                if ($source === null && ($settings['discovery_enabled'] === true) && ($fresh['next_discovery_at'] === null || $fresh['next_discovery_at'] <= $now)) {
                    $this->insert($policy, null, null, null, 'discovery:' . $policy['id'] . ':' . $policy['version'] . ':' . $now, 'discovery');
                    $this->db->execute('UPDATE content_automation_settings SET next_discovery_at=? WHERE workspace_id=? AND id=?', [DbTime::format($this->clock->now()->modify('+' . $settings['interval_minutes'] . ' minutes')), $ctx->workspaceId, $policy['id']]);
                }
                $sql = 'SELECT i.* FROM source_items i WHERE i.workspace_id=? AND i.source_id <=> ? AND i.id > ? AND (? IS NOT NULL OR EXISTS (SELECT 1 FROM discovery_imports l WHERE l.workspace_id=i.workspace_id AND l.material_id=i.id)) AND NOT EXISTS (SELECT 1 FROM content_automation_runs r WHERE r.workspace_id=i.workspace_id AND r.item_id=i.id AND r.settings_version=? AND r.settings_id=? AND r.status IN (?, ?)) ORDER BY i.id LIMIT 100';
                $bindings = [$ctx->workspaceId, $source?->id, (int) $fresh['scan_cursor'], $source?->id, $policy['version'], $policy['id'], 'pending', 'waiting'];
                $items = $this->db->select($sql, $bindings);
                if ($items === [] && (int) $fresh['scan_cursor'] > 0) {
                    $bindings[2] = 0;
                    $items = $this->db->select($sql, $bindings);
                }
                $cursor = $items === [] ? 0 : (int) $items[count($items) - 1]['id'];
                $this->db->execute('UPDATE content_automation_settings SET scan_cursor=? WHERE workspace_id=? AND id=?', [$cursor, $ctx->workspaceId, $policy['id']]);
                foreach ($items as $item) {
                    $revision = MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id']));
                    $hash = MaterialRepository::selectionHash($this->materials->selection($ctx, $source, (int) $item['id']));
                    $key = hash('sha256', 'material:' . $item['id'] . ':' . $revision . ':' . $policy['version']);
                    $prior = $this->db->select('SELECT * FROM content_automation_runs WHERE workspace_id=? AND run_key=?', [$ctx->workspaceId, $key])[0] ?? null;
                    if ($prior === null) {
                        $this->insert($policy, (int) $item['id'], $revision, $hash, $key, 'selection');
                    } elseif ($prior['status'] === 'paused') {
                        $this->db->execute('UPDATE content_automation_runs SET status=?, available_at=?, error=NULL, finished_at=NULL WHERE workspace_id=? AND id=?', ['pending', $now, $ctx->workspaceId, $prior['id']]);
                    } elseif (in_array($prior['status'], ['needs_review', 'rejected', 'completed', 'stale'], true) && $prior['selection_hash'] !== $hash) {
                        $this->db->execute('UPDATE content_automation_runs SET status=?, step=?, selection_hash=?, available_at=?, updated_at=?, finished_at=NULL, error=NULL, text_version=NULL, video_version=NULL, draft_id=NULL WHERE workspace_id=? AND id=?', ['pending', 'selection', $hash, $now, $now, $ctx->workspaceId, $prior['id']]);
                    }
                }
            });
        }
        $this->db->transaction(function (): void {
            $now = DbTime::format($this->clock->now());
            $rows = $this->db->select('SELECT id FROM content_automation_runs WHERE status IN (?, ?) AND available_at <= ? AND (queued_at IS NULL OR queued_at < ?) ORDER BY id LIMIT 100 FOR UPDATE SKIP LOCKED', ['pending', 'waiting', $now, DbTime::format($this->clock->now()->modify('-16 minutes'))]);
            foreach ($rows as $row) {
                $this->queue->dispatch(new AutomationJob((int) $row['id']), queue: 'default');
                $this->db->execute('UPDATE content_automation_runs SET queued_at=? WHERE id=?', [$now, $row['id']]);
            }
        });
    }

    /** @param array<string,mixed> $policy */
    private function insert(array $policy, ?int $itemId, ?string $revision, ?string $hash, string $key, string $step): void
    {
        $now = DbTime::format($this->clock->now());
        $this->db->execute('INSERT IGNORE INTO content_automation_runs (workspace_id, settings_id, item_id, run_key, revision_hash, selection_hash, settings_version, settings_snapshot_json, step, available_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [$policy['workspace_id'], $policy['id'], $itemId, $key, $revision, $hash, $policy['version'], $policy['settings_json'], $step, $now, $now, $now]);
    }

    /** Same-connection advisory lock spans provider calls without a long SQL transaction. Crash releases it automatically. */
    public function run(int $id): void
    {
        $run = $this->db->table('content_automation_runs')->where('id', '=', $id)->first();
        if ($run === null || !in_array($run['status'], ['pending', 'waiting'], true)) {
            return;
        }
        $lock = 'content-automation:' . $run['workspace_id'] . ':' . ($run['item_id'] ?? ('discovery-' . $run['settings_id']));
        if ((int) $this->db->select('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]['acquired'] !== 1) {
            return;
        }
        $slot = null;
        for ($i = 0; $i < $this->operationsConfig->int('content_operations.concurrency', 2); ++$i) {
            $name = 'content-worker-slot:' . $i;
            if ((int) $this->db->select('SELECT GET_LOCK(?, 0) AS acquired', [$name])[0]['acquired'] === 1) {
                $slot = $name;
                break;
            }
        }
        if ($slot === null) {
            $this->db->select('SELECT RELEASE_LOCK(?)', [$lock]);
            return;
        }
        try {
            $run = $this->db->table('content_automation_runs')->where('id', '=', $id)->first();
            if ($run === null || !in_array($run['status'], ['pending', 'waiting'], true)) {
                return;
            }
            $policy = $this->db->table('content_automation_settings')->where('workspace_id', '=', $run['workspace_id'])->where('id', '=', $run['settings_id'])->first();
            if ($policy === null || (int) $policy['version'] !== (int) $run['settings_version']) {
                $this->finish($run, 'stale', 'Настройки изменились.');
                return;
            }
            $settings = json_decode((string) $policy['settings_json'], true, 32, JSON_THROW_ON_ERROR);
            [$ctx, $source] = $this->scope($policy);
            if (!($settings['enabled'] === true) || ($source !== null && !$source->enabled)) {
                $this->finish($run, 'paused', 'Автоматизация или источник выключены.');
                return;
            }
            if ($run['item_id'] === null) {
                $this->discover($ctx, $run, $settings);
                return;
            }
            $this->material($ctx, $source, $run, $settings);
        } catch (HttpException $e) {
            if ($e->status === 409) {
                $this->finish($run, 'stale', 'Материал, настройки или решение изменились.');
                return;
            }
            $this->retry($run);
        } catch (Throwable) {
            $this->retry($run);
        } finally {
            $this->db->select('SELECT RELEASE_LOCK(?)', [$lock]);
            $this->db->select('SELECT RELEASE_LOCK(?)', [$slot]);
        }
    }

    /** @param array<string,mixed> $run */
    private function retry(array $run): never
    {
        $attempts = (int) $run['attempts'] + 1;
        $this->db->execute('UPDATE content_automation_runs SET attempts=?, status=?, error=?, available_at=?, updated_at=? WHERE workspace_id=? AND id=?', [$attempts, $attempts >= 5 ? 'failed' : 'pending', 'Шаг не завершён. Повторная попытка или ручная проверка.', DbTime::format($this->clock->now()->modify('+' . [60, 300, 900, 3600, 3600][min($attempts - 1, 4)] . ' seconds')), DbTime::format($this->clock->now()), $run['workspace_id'], $run['id']]);
        // Never expose exception chains, provider bodies or source text to Worker logs.
        throw new \RuntimeException('Content automation step failed.');
    }

    /** @param array<string,mixed> $policy
     * @return array{WorkspaceContext,?Source} */
    private function scope(array $policy): array
    {
        $workspace = $this->workspaces->findById((int) $policy['workspace_id']) ?? throw new HttpException(404, 'Not found');
        $member = $this->workspaces->membership($workspace->id, $workspace->ownerId) ?? throw new HttpException(404, 'Not found');
        $ctx = WorkspaceContext::from($workspace, $member);
        if ($policy['source_id'] === null) {
            return [$ctx, null];
        }
        $raw = $this->db->table('sources')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $policy['source_id'])->first() ?? throw new HttpException(404, 'Not found');
        return [$ctx, $this->sources->find($ctx, (string) $raw['public_id']) ?? throw new HttpException(404, 'Not found')];
    }

    /** @param array<string,mixed> $run
     * @param array<string,mixed> $settings */
    private function discover(WorkspaceContext $ctx, array $run, array $settings): void
    {
        if (!$this->guard->discovery()) {
            $this->finish($run, 'needs_review', 'Discovery providers пока демонстрационные и отключены в production.');
            return;
        }
        $this->discovery->refresh($ctx, $settings['provider_types'], (int) $settings['candidate_limit']);
        $types = $settings['provider_types'];
        $placeholders = implode(',', array_fill(0, count($types), '?'));
        foreach ($this->db->select('SELECT public_id FROM discovery_items WHERE workspace_id=? AND status=? AND source_type IN (' . $placeholders . ') ORDER BY discovered_at DESC, id DESC LIMIT ' . (int) $settings['candidate_limit'], [$ctx->workspaceId, 'new', ...$types]) as $candidate) {
            $this->gateway->import($ctx, (string) $candidate['public_id']);
        }
        $this->finish($run, 'completed', null, 'discovery');
    }

    /** @param array<string,mixed> $run
     * @param array<string,mixed> $settings */
    private function material(WorkspaceContext $ctx, ?Source $source, array $run, array $settings): void
    {
        $item = $this->db->table('source_items')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $run['item_id'])->first() ?? throw new HttpException(404, 'Not found');
        $itemId = (string) $item['public_id'];
        $revision = MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id']));
        if ($revision !== $run['revision_hash']) {
            $this->finish($run, 'stale', 'Материал изменился.');
            return;
        }
        if ($run['step'] === 'selection_started') {
            $this->finish($run, 'needs_review', 'Отбор не подтверждён. Проверьте решение перед новым вызовом.');
            return;
        }
        if ($run['step'] === 'selection' && ($settings['auto_selection'] === true) && ($this->materials->selection($ctx, $source, (int) $item['id'])['decision_mode'] ?? '') !== 'manual') {
            if (($settings['semantic_selection'] === true) && (!$this->guard->allows('semantic') || !$this->semanticEnabled($ctx, $source))) {
                $this->finish($run, 'needs_review', 'Смысловой provider недоступен.');
                return;
            }
            $this->checkpoint($run, 'selection_started', '');
            $this->db->transaction(function () use ($ctx, $source, $item): void {
                if ($source === null) {
                    $this->db->select('SELECT id FROM workspaces WHERE id=? FOR UPDATE', [$ctx->workspaceId]);
                    $this->selection->evaluateDiscoveryMaterialLocked($ctx->workspaceId, (int) $item['id'], true);
                } else {
                    $this->db->select('SELECT id FROM sources WHERE workspace_id=? AND id=? FOR UPDATE', [$ctx->workspaceId, $source->id]);
                    $this->selection->evaluateLocked($ctx->workspaceId, $source->id, (int) $item['id'], true);
                }
            });
        }
        $decision = $this->materials->selection($ctx, $source, (int) $item['id']);
        $hash = MaterialRepository::selectionHash($decision);
        if (($decision['selection_status'] ?? '') !== 'approved') {
            $this->db->execute('UPDATE content_automation_runs SET selection_hash=? WHERE workspace_id=? AND id=?', [$hash, $ctx->workspaceId, $run['id']]);
            $this->finish($run, ($decision['selection_status'] ?? '') === 'rejected' ? 'rejected' : 'needs_review', null, 'selection');
            return;
        }
        if (!in_array($run['step'], ['selection', 'selection_started'], true) && $run['selection_hash'] !== $hash) {
            $this->finish($run, 'stale', 'Решение отбора изменилось.');
            return;
        }
        if (in_array($run['step'], ['selection', 'selection_started'], true)) {
            $run['selection_hash'] = $hash;
            $this->db->execute('UPDATE content_automation_runs SET selection_hash=? WHERE workspace_id=? AND id=?', [$hash, $ctx->workspaceId, $run['id']]);
            $this->checkpoint($run, 'text', 'selection');
        }
        if ($run['step'] === 'text' || $run['step'] === 'text_started') {
            if (($settings['auto_text_processing'] === true)) {
                if ($settings['text_mode'] !== 'unchanged' && !$this->guard->allows('text')) {
                    $this->finish($run, 'needs_review', 'Текстовый provider недоступен.');
                    return;
                }
                $row = $this->db->select('SELECT * FROM source_text_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? AND mode=? ORDER BY id DESC LIMIT 1', [$ctx->workspaceId, $item['id'], $revision, $hash, $settings['text_mode']])[0] ?? null;
                if ($row === null && $run['step'] !== 'text_started') {
                    $this->checkpoint($run, 'text_started', 'selection');
                    $this->assertCurrent($ctx, $source, $run);
                    $this->text->process($ctx, $source, $itemId, $revision, TextSettings::fromInput(['mode' => $settings['text_mode']]));
                    $row = $this->db->select('SELECT * FROM source_text_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? AND mode=? ORDER BY id DESC LIMIT 1', [$ctx->workspaceId, $item['id'], $revision, $hash, $settings['text_mode']])[0] ?? null;
                }
                if (($row['status'] ?? '') !== 'completed') {
                    $this->finish($run, 'needs_review', 'Обработка текста не подтверждена. Проверьте историю перед повторным вызовом.');
                    return;
                }
                $run['text_version'] = $row['public_id'];
                $this->db->execute('UPDATE content_automation_runs SET text_version=? WHERE workspace_id=? AND id=?', [$row['public_id'], $ctx->workspaceId, $run['id']]);
            }
            $this->checkpoint($run, 'images', ($settings['auto_text_processing'] === true) ? 'text' : 'selection');
        }
        if ($run['step'] === 'images') {
            if ($source !== null && ($settings['auto_image_processing'] === true) && $item['content_type'] !== 'text') {
                if (!$this->guard->allows('image_search') || !$this->guard->allows('image_enhancement')) {
                    $this->finish($run, 'needs_review', 'Provider изображений недоступен.');
                    return;
                }
                $rows = $this->db->select('SELECT * FROM source_image_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? ORDER BY id', [$ctx->workspaceId, $item['id'], $revision, $hash]);
                if ($rows === []) {
                    $this->assertCurrent($ctx, $source, $run);
                    $this->images->request($ctx, $source, $itemId, $revision);
                    $this->wait($run);
                    return;
                }
                foreach ($rows as $row) {
                    if ($row['status'] === 'queued') {
                        $this->wait($run);
                        return;
                    }
                    if ($row['status'] !== 'completed' || $row['error'] !== null) {
                        $this->finish($run, 'needs_review', 'Изображения требуют ручной проверки.');
                        return;
                    }
                }
            }
            $this->checkpoint($run, 'video', ($settings['auto_image_processing'] === true) ? 'images' : (string) $run['last_successful_step']);
        }
        if ($run['step'] === 'video') {
            if (($settings['auto_video_generation'] === true)) {
                if ($source === null || !$this->guard->allows('video')) {
                    $this->finish($run, 'needs_review', 'Видео для этого материала недоступно.');
                    return;
                }
                if ($run['video_version'] === null) {
                    $videoId = $this->db->transaction(function () use ($ctx, $source, $itemId, $revision, $run): string {
                        $id = $this->videos->request($ctx, $source, $itemId, $revision, VideoSettings::fromInput(['text_version' => $run['text_version'] ?? '']));
                        $this->db->execute('UPDATE content_automation_runs SET video_version=? WHERE workspace_id=? AND id=?', [$id, $ctx->workspaceId, $run['id']]);
                        return $id;
                    });
                    $run['video_version'] = $videoId;
                }
                $this->assertCurrent($ctx, $source, $run);
                $status = $this->videos->run($ctx, $source, $itemId, (string) $run['video_version']);
                if ($status === 'processing') {
                    $this->wait($run);
                    return;
                }
                if ($status !== 'completed') {
                    $this->finish($run, 'needs_review', 'Видео требует проверки у провайдера.');
                    return;
                }
                $this->videos->choose($ctx, $source, $itemId, (string) $run['video_version']);
            }
            $this->checkpoint($run, 'draft', ($settings['auto_video_generation'] === true) ? 'video' : (string) $run['last_successful_step']);
        }
        if (($settings['auto_draft'] === true)) {
            $text = $run['text_version'] ?? ($this->db->select('SELECT public_id FROM source_text_processings WHERE workspace_id=? AND item_id=? AND revision_hash=? AND selection_hash=? AND status=? ORDER BY id DESC LIMIT 1', [$ctx->workspaceId, $item['id'], $revision, $hash, 'completed'])[0]['public_id'] ?? null);
            if ($text === null) {
                $this->finish($run, 'needs_review', 'Выберите обработанный текст перед созданием черновика.');
                return;
            }
            $choices = $this->drafts->videoChoices($ctx, $source, $itemId);
            $video = isset($choices[$run['video_version'] ?? '']) ? (string) ($run['video_version'] ?? '') : '';
            try {
                $this->assertCurrent($ctx, $source, $run);
                $post = $this->drafts->create($ctx, $source, $itemId, $revision, (string) $text, $video);
            } catch (HttpException $e) {
                if ($e->status !== 409) {
                    throw $e;
                }
                $this->finish($run, 'needs_review', 'Проверьте выбранные результаты перед созданием черновика.');
                return;
            }
            $this->db->execute('UPDATE content_automation_runs SET draft_id=? WHERE workspace_id=? AND id=?', [$post->id, $ctx->workspaceId, $run['id']]);
            $this->finish($run, 'completed', null, 'draft');
            return;
        }
        $this->finish($run, 'needs_review', 'Материал готов к ручному созданию черновика.');
    }

    private function semanticEnabled(WorkspaceContext $ctx, ?Source $source): bool
    {
        $row = $this->db->select('SELECT settings_json FROM semantic_selection_settings WHERE workspace_id=? AND scope_key=?', [$ctx->workspaceId, \App\Domain\Source\Selection\SemanticSelection::scope($source?->id)])[0] ?? null;
        return $row !== null && (json_decode((string) $row['settings_json'], true, 32, JSON_THROW_ON_ERROR)['enabled'] ?? false) === true;
    }

    /** @param array<string,mixed> $run */
    private function assertCurrent(WorkspaceContext $ctx, ?Source $source, array $run): void
    {
        $policy = $this->db->select('SELECT version, settings_json FROM content_automation_settings WHERE workspace_id=? AND id=?', [$ctx->workspaceId, $run['settings_id']])[0] ?? null;
        $sourceEnabled = $source === null || (int) ($this->db->select('SELECT enabled FROM sources WHERE workspace_id=? AND id=?', [$ctx->workspaceId, $source->id])[0]['enabled'] ?? 0) === 1;
        $item = $this->db->table('source_items')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $run['item_id'])->first();
        if ($policy === null || (int) $policy['version'] !== (int) $run['settings_version'] || (json_decode((string) $policy['settings_json'], true, 32, JSON_THROW_ON_ERROR)['enabled'] ?? false) !== true || !$sourceEnabled || $item === null || MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id'])) !== $run['revision_hash'] || MaterialRepository::selectionHash($this->materials->selection($ctx, $source, (int) $item['id'])) !== $run['selection_hash']) {
            throw new HttpException(409, 'Automation revision changed');
        }
    }

    /** @param array<string,mixed> $run */
    private function checkpoint(array &$run, string $step, string $success): void
    {
        $run['step'] = $step;
        $run['last_successful_step'] = $success;
        $this->db->execute('UPDATE content_automation_runs SET step=?, last_successful_step=?, status=?, error=NULL, updated_at=? WHERE workspace_id=? AND id=?', [$step, $success, 'pending', DbTime::format($this->clock->now()), $run['workspace_id'], $run['id']]);
    }
    /** @param array<string,mixed> $run */
    private function finish(array $run, string $status, ?string $error, ?string $success = null): void
    {
        if ($status === 'needs_review' && $error !== null && $error !== 'Материал готов к ручному созданию черновика.') {
            $settings = json_decode((string) $run['settings_snapshot_json'], true, 32, JSON_THROW_ON_ERROR);
            if ($settings['manual_review_fallback'] !== true) {
                $status = 'failed';
            }
        }
        $now = DbTime::format($this->clock->now());
        $this->db->execute('UPDATE content_automation_runs SET status=?, error=?, last_successful_step=COALESCE(?,last_successful_step), queued_at=NULL, finished_at=?, updated_at=? WHERE workspace_id=? AND id=?', [$status, $error, $success, $now, $now, $run['workspace_id'], $run['id']]);
    }
    /** @param array<string,mixed> $run */
    private function wait(array $run): void
    {
        if ((string) $run['created_at'] < DbTime::format($this->clock->now()->modify('-24 hours'))) {
            $this->finish($run, 'needs_review', 'Превышено время ожидания. Проверьте reader или provider.');
            return;
        }
        $this->db->execute('UPDATE content_automation_runs SET status=?, queued_at=NULL, available_at=?, updated_at=? WHERE workspace_id=? AND id=?', ['waiting', DbTime::format($this->clock->now()->modify('+60 seconds')), DbTime::format($this->clock->now()), $run['workspace_id'], $run['id']]);
    }
}
