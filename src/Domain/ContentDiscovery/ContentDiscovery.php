<?php

declare(strict_types=1);

namespace App\Domain\ContentDiscovery;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Discovery\DiscoveryProviders;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Automatic provider discovery independent of Sources; serialized dedupe and bounded deterministic story aggregation. */
final class ContentDiscovery
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly DiscoveryProviders $providers, private readonly DiscoveryClustering $clustering, private readonly TrendScore $score, private readonly AuditLog $audit, private readonly \App\Domain\Source\Selection\SelectionService $selection)
    {
    }
    /** Calls providers outside locks, commits each batch and records only safe run outcomes. Returns newly created items. */
    public function refresh(WorkspaceContext $ctx): int
    {
        $created = 0;
        foreach ($this->providers->providers as $provider) {
            $name = $provider->name();
            if (preg_match('/^[a-z0-9_-]{1,32}$/D', $name) !== 1 || !in_array($provider->sourceType(), ['telegram', 'web', 'social'], true)) {
                throw new HttpException(503, 'Провайдер обнаружения недоступен.');
            }
            $run = (string) new Ulid();
            $this->db->table('discovery_runs')->insert(['public_id' => $run, 'workspace_id' => $ctx->workspaceId, 'provider' => $name, 'status' => 'processing', 'created_at' => DbTime::format($this->clock->now())]);
            try {
                $batch = $provider->discover($this->clock->now());
                if (count($batch) > 200) {
                    throw new \RuntimeException('Discovery batch too large');
                }
                $count = $this->db->transaction(function () use ($ctx, $batch, $name, $provider): int {
                    $this->lock($ctx);
                    $count = 0;
                    foreach ($batch as $item) {
                        if ($item->provider !== $name || $item->sourceType !== $provider->sourceType() || ($item->publishedAt !== null && $item->publishedAt > $this->clock->now()->modify('+5 minutes'))) {
                            throw new \RuntimeException('Invalid provider item');
                        }
                        $count += $this->ingest($ctx, $item) ? 1 : 0;
                    }
                    return $count;
                });
                $created += $count;
                $this->db->execute('UPDATE discovery_runs SET status = ?, found_count = ?, created_count = ?, finished_at = ? WHERE workspace_id = ? AND public_id = ?', ['completed', count($batch), $count, DbTime::format($this->clock->now()), $ctx->workspaceId, $run]);
            } catch (Throwable) {
                $this->db->execute('UPDATE discovery_runs SET status = ?, error = ?, finished_at = ? WHERE workspace_id = ? AND public_id = ?', ['failed', 'Обнаружение не удалось. Повторите попытку позже.', DbTime::format($this->clock->now()), $ctx->workspaceId, $run]);
            }
        }
        $this->db->transaction(function () use ($ctx, $created): void {
            $this->lock($ctx);
            foreach ($this->db->select('SELECT id FROM discovery_clusters WHERE workspace_id = ? ORDER BY id', [$ctx->workspaceId]) as $cluster) {
                $rows = $this->db->select('SELECT * FROM discovery_items WHERE workspace_id = ? AND cluster_id = ? ORDER BY id', [$ctx->workspaceId, $cluster['id']]);
                $result = $this->score->calculate($rows, $this->clock->now());
                $now = DbTime::format($this->clock->now());
                $this->db->execute('UPDATE discovery_clusters SET trend_score = ?, score_json = ?, updated_at = ? WHERE workspace_id = ? AND id = ?', [$result['score'], json_encode($result, JSON_THROW_ON_ERROR), $now, $ctx->workspaceId, $cluster['id']]);
                $this->db->execute('UPDATE discovery_items SET trend_score = ? WHERE workspace_id = ? AND cluster_id = ?', [$result['score'], $ctx->workspaceId, $cluster['id']]);
            }
            $this->selection->evaluateCandidatesLocked($ctx->workspaceId);
            $this->audit->record('discovery.refreshed', $ctx->userId, null, null, ['created' => $created], $ctx->workspaceId);
        });
        return $created;
    }
    private function ingest(WorkspaceContext $ctx, DiscoveryItem $item): bool
    {
        $keys = DiscoveryIdentity::keys($item);
        $matches = [];
        foreach ($keys as $type => $hash) {
            foreach ($this->db->select('SELECT item_id FROM discovery_item_keys WHERE workspace_id = ? AND key_type = ? AND key_hash = ?', [$ctx->workspaceId, $type, $hash]) as $row) {
                $matches[(int) $row['item_id']] = true;
            }
        }
        if (count($matches) > 1) {
            // Conflicting aliases must never join unrelated articles or lose their histories.
            throw new \RuntimeException('Discovery identity collision');
        }
        $now = DbTime::format($this->clock->now());
        if ($matches !== []) {
            $id = array_key_first($matches);
            $existing = $this->db->select('SELECT providers_json, source_key FROM discovery_items WHERE workspace_id = ? AND id = ?', [$ctx->workspaceId, $id])[0];
            $providers = json_decode((string) $existing['providers_json'], true, 32, JSON_THROW_ON_ERROR);
            $providers = array_values(array_unique([...$providers, $item->provider]));
            $this->db->execute('UPDATE discovery_items SET providers_json = ?, updated_at = ? WHERE workspace_id = ? AND id = ?', [json_encode($providers, JSON_THROW_ON_ERROR), $now, $ctx->workspaceId, $id]);
            if ($existing['source_key'] === $item->sourceKey) {
                $this->db->execute('UPDATE discovery_items SET engagement_json = ? WHERE workspace_id = ? AND id = ?', [json_encode($item->engagement, JSON_THROW_ON_ERROR), $ctx->workspaceId, $id]);
            }
            $this->aliases($ctx, $id, $keys);
            return false;
        }
        $tokens = $this->clustering->tokens($item->title . ' ' . $item->excerpt);
        $story = $item->publishedAt ?? $this->clock->now();
        $clusterId = null;
        $best = 0.45;
        foreach ($this->db->select('SELECT id, story_at, tokens_json FROM discovery_clusters WHERE workspace_id = ? AND story_at >= ? AND story_at <= ? ORDER BY id DESC LIMIT 200', [$ctx->workspaceId, DbTime::format($story->modify('-36 hours')), DbTime::format($story->modify('+36 hours'))]) as $row) {
            $score = $this->clustering->similarity($tokens, json_decode((string) $row['tokens_json'], true, 32, JSON_THROW_ON_ERROR), (int) (abs($story->getTimestamp() - (new DateTimeImmutable((string) $row['story_at'], new \DateTimeZone('UTC')))->getTimestamp()) / 3600));
            if ($score > $best) {
                $clusterId = (int) $row['id'];
                $best = $score;
            }
        }
        if ($clusterId === null) {
            $this->db->table('discovery_clusters')->insert(['public_id' => (string) new Ulid(), 'workspace_id' => $ctx->workspaceId, 'title' => $item->title, 'summary' => $item->excerpt, 'tokens_json' => json_encode($tokens, JSON_THROW_ON_ERROR), 'story_at' => DbTime::format($story), 'discovered_at' => $now, 'score_json' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            $clusterId = (int) $this->db->lastInsertId();
        }
        $status = (string) $this->db->select('SELECT status FROM discovery_clusters WHERE workspace_id = ? AND id = ?', [$ctx->workspaceId, $clusterId])[0]['status'];
        $this->db->table('discovery_items')->insert(['public_id' => (string) new Ulid(), 'workspace_id' => $ctx->workspaceId, 'cluster_id' => $clusterId, 'provider' => $item->provider, 'source_type' => $item->sourceType, 'source_key' => $item->sourceKey, 'source_name' => $item->sourceName, 'canonical_url' => DiscoveryIdentity::url($item->url), 'external_id' => $item->externalId, 'title' => $item->title, 'excerpt' => $item->excerpt, 'normalized_title' => DiscoveryIdentity::title($item->title), 'fingerprint' => $keys['fingerprint'], 'published_at' => $item->publishedAt === null ? null : DbTime::format($item->publishedAt), 'discovered_at' => $now, 'metadata_json' => json_encode($item->metadata, JSON_THROW_ON_ERROR), 'engagement_json' => json_encode($item->engagement, JSON_THROW_ON_ERROR), 'providers_json' => json_encode([$item->provider], JSON_THROW_ON_ERROR), 'language' => $item->language, 'status' => $status === 'ignored' ? 'ignored' : 'new', 'created_at' => $now, 'updated_at' => $now]);
        $this->aliases($ctx, (int) $this->db->lastInsertId(), $keys);
        return true;
    }
    /** @param array<string,string> $keys */
    private function aliases(WorkspaceContext $ctx, int $id, array $keys): void
    {
        foreach ($keys as $type => $hash) {
            $this->db->execute('INSERT INTO discovery_item_keys (workspace_id, key_type, key_hash, item_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE item_id = item_id', [$ctx->workspaceId, $type, $hash, $id]);
        }
    }
    /** Ignore an entire story; future matches retain that state. Imported content is never removed. */
    public function ignore(WorkspaceContext $ctx, string $clusterId): void
    {
        $this->db->transaction(function () use ($ctx, $clusterId): void {
            $this->lock($ctx);
            $row = $this->db->select('SELECT id FROM discovery_clusters WHERE workspace_id = ? AND public_id = ?', [$ctx->workspaceId, $clusterId])[0] ?? throw new HttpException(404, 'Not found');
            $now = DbTime::format($this->clock->now());
            $this->db->execute('UPDATE discovery_clusters SET status = ?, updated_at = ? WHERE workspace_id = ? AND id = ?', ['ignored', $now, $ctx->workspaceId, $row['id']]);
            $this->db->execute('UPDATE discovery_items SET status = ?, updated_at = ? WHERE workspace_id = ? AND cluster_id = ? AND status <> ?', ['ignored', $now, $ctx->workspaceId, $row['id'], 'imported']);
            $this->audit->record('discovery.ignored', $ctx->userId, 'discovery_cluster', $clusterId, [], $ctx->workspaceId);
        });
    }
    private function lock(WorkspaceContext $ctx): void
    {
        if ($this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]) === []) {
            throw new HttpException(404, 'Not found');
        }
    }
}
