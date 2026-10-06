<?php

declare(strict_types=1);

namespace App\Domain\Content\ImageProcessing;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Source;
use App\Domain\Source\SourceRepository;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Images\ImageEnhancementProvider;
use App\Integrations\Images\ImageSearchProvider;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;

/** Photo processing is independent of text, source selection and the publication queue. */
final class ImageWorkflow
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly MaterialRepository $materials, private readonly ImageRepository $repository, private readonly ImageFiles $files, private readonly ImageAnalysis $analysis, private readonly ImageSearchProvider $search, private readonly ImageEnhancementProvider $enhancement, private readonly AuditLog $audit, private readonly SourceRepository $sources, private readonly \App\Domain\Workspace\WorkspaceRepository $workspaces, private readonly \App\Domain\Content\Automation\AutomationCalls $calls)
    {
    }

    public function request(WorkspaceContext $context, Source $source, string $itemId, string $revision): int
    {
        return $this->db->transaction(function () use ($context, $source, $itemId, $revision): int {
            $this->lock($context, $source);
            $item = $this->materials->item($context, $source, $itemId);
            if (!$source->enabled) {
                throw new HttpException(409, 'Включите источник для загрузки фотографий.');
            }
            if (!$this->current($context, $source, $item, $revision, null)) {
                throw new HttpException(409, 'Примите актуальную версию материала и обновите страницу.');
            }
            $selectionHash = MaterialRepository::selectionHash($this->materials->selection($context, $source, (int) $item['id']));
            $messages = $this->db->select('SELECT * FROM source_messages WHERE workspace_id = ? AND source_id = ? AND item_id = ? ORDER BY message_id', [$context->workspaceId, $source->id, $item['id']]);
            $count = 0;
            foreach ($messages as $message) {
                if (isset(json_decode((string) $message['metadata_json'], true, 32, JSON_THROW_ON_ERROR)['deleted_at'])) {
                    continue;
                }
                $media = $message['media_json'] === null ? null : json_decode((string) $message['media_json'], true, 32, JSON_THROW_ON_ERROR);
                if (!is_array($media) || ($media['kind'] ?? '') !== 'photo' || !is_string($media['telegram_id'] ?? null) || preg_match('/^\d{1,32}$/D', $media['telegram_id']) !== 1) {
                    continue;
                }
                if ($this->db->select("SELECT id FROM source_image_processings WHERE workspace_id = ? AND source_id = ? AND message_id = ? AND revision_hash = ? AND selection_hash = ? AND status = 'queued'", [$context->workspaceId, $source->id, $message['id'], $revision, $selectionHash]) !== []) {
                    continue;
                }
                if ((int) $this->db->select("SELECT COUNT(*) AS n FROM source_image_processings WHERE workspace_id=? AND source_id=? AND status='queued'", [$context->workspaceId, $source->id])[0]['n'] >= 50) {
                    throw new HttpException(429, 'Дождитесь загрузки текущих изображений.');
                }
                $now = DbTime::format($this->clock->now());
                $this->db->table('source_image_processings')->insert(['public_id' => (string) new Ulid(), 'workspace_id' => $context->workspaceId, 'source_id' => $source->id, 'item_id' => $item['id'], 'message_id' => $message['id'], 'telegram_message_id' => $message['message_id'], 'peer_id' => $item['peer_id'], 'telegram_photo_id' => $media['telegram_id'], 'revision_hash' => $revision, 'selection_hash' => $selectionHash, 'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now]);
                ++$count;
            }
            if ($count > 0) {
                $this->audit->record('source.images_requested', $context->userId, 'source_item', $itemId, ['photos' => $count], $context->workspaceId);
            }
            return $count;
        });
    }

    /** Explicit selection; weak matches require a separate human confirmation, never an automatic replacement. */
    public function choose(WorkspaceContext $context, Source $source, string $itemId, string $variantId, bool $confirmed): void
    {
        $this->db->transaction(function () use ($context, $source, $itemId, $variantId, $confirmed): void {
            $this->lock($context, $source);
            $item = $this->materials->item($context, $source, $itemId);
            $variant = $this->repository->variant($context, $source, (int) $item['id'], $variantId);
            if ($variant['status'] !== 'completed' || !$this->current($context, $source, $item, (string) $variant['revision_hash'], (string) $variant['selection_hash'])) {
                throw new HttpException(409, 'Результат устарел или материал не принят.');
            }
            if ($variant['kind'] === 'candidate' && $variant['verification'] !== 'verified' && !$confirmed) {
                throw new HttpException(409, 'Подтвердите совпадение изображений вручную.');
            }
            $this->db->execute('UPDATE source_image_processings SET selected_variant = ?, updated_at = ? WHERE workspace_id = ? AND source_id = ? AND id = ?', [$variantId, DbTime::format($this->clock->now()), $context->workspaceId, $source->id, $variant['processing_id']]);
            $this->audit->record('source.image_selected', $context->userId, 'source_item', $itemId, ['variant' => $variant['kind']], $context->workspaceId);
        });
    }

    /** @param array<string,mixed> $item */
    public function current(WorkspaceContext $context, Source $source, array $item, string $revision, ?string $selectionHash): bool
    {
        $binding = $this->db->select('SELECT connection_version FROM sources WHERE workspace_id=? AND id=?', [$context->workspaceId, $source->id])[0] ?? null;
        if ($binding === null || (int) $binding['connection_version'] !== $source->connectionVersion || (int) $item['connection_version'] !== $source->connectionVersion) {
            return false;
        }
        $selection = $this->materials->selection($context, $source, (int) $item['id']);
        return ($selection['selection_status'] ?? '') === 'approved' && hash_equals($revision, MaterialRepository::revision($item, $this->materials->messages($context, $source, (int) $item['id']))) && ($selectionHash === null || hash_equals($selectionHash, MaterialRepository::selectionHash($selection)));
    }

    /** Pre-context service boundary: only enabled, current approved jobs, globally bounded and free of private credentials.
     * @return list<array<string,mixed>>
     */
    /** @param list<string>|null $availableSources
     * @return list<array<string,mixed>> */
    public function jobs(?array $availableSources = null): array
    {
        $jobs = [];
        if ($availableSources === []) {
            return [];
        }
        $bindings = $availableSources ?? [];
        foreach ($bindings as $id) {
            if (!Ulid::isValid($id)) {
                throw new HttpException(422, 'Invalid reader source');
            }
        }
        $filter = $availableSources === null ? '' : ' AND s.public_id IN (' . implode(',', array_fill(0, count($bindings), '?')) . ')';
        foreach ($this->db->select("SELECT * FROM (SELECT p.*, s.public_id AS source_public_id, ROW_NUMBER() OVER (PARTITION BY p.source_id ORDER BY p.id) AS fair_rank FROM source_image_processings p JOIN sources s ON s.id=p.source_id AND s.workspace_id=p.workspace_id WHERE s.enabled=1 AND p.status='queued'" . $filter . ') ranked ORDER BY fair_rank, id LIMIT 100', $bindings) as $row) {
            [$ctx, $source, $item] = $this->scope($row);
            if (!$this->current($ctx, $source, $item, (string) $row['revision_hash'], (string) $row['selection_hash'])) {
                $this->finish($row, 'stale', 'Материал или решение отбора изменились.');
                continue;
            }
            $jobs[] = ['job_id' => $row['public_id'], 'source_id' => $source->publicId, 'peer_id' => $row['peer_id'], 'message_id' => $row['telegram_message_id'], 'photo_id' => $row['telegram_photo_id'], 'max_bytes' => 16777216];
        }
        return $jobs;
    }

    /** Commit-before-ACK, duplicate deliveries return the existing durable result, no duplicate archives.
     * @param array<string,mixed> $payload
     * @return array{ack:bool,job_id:string,duplicate:bool,status:string}
     */
    public function accept(array $payload): array
    {
        $id = is_string($payload['job_id'] ?? null) ? $payload['job_id'] : '';
        if (!Ulid::isValid($id)) {
            throw new HttpException(422, 'Invalid job');
        }
        $lock = 'content-photo:' . $id;
        if ((int) $this->db->select('SELECT GET_LOCK(?, 0) AS acquired', [$lock])[0]['acquired'] !== 1) {
            throw new HttpException(503, 'Image processing busy');
        }
        try {
            return $this->acceptLocked($payload);
        } finally {
            $this->db->select('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** @param array<string,mixed> $payload
     * @return array{ack:bool,job_id:string,duplicate:bool,status:string} */
    private function acceptLocked(array $payload): array
    {
        $id = is_string($payload['job_id'] ?? null) ? $payload['job_id'] : '';
        if (!Ulid::isValid($id)) {
            throw new HttpException(422, 'Invalid job');
        }
        $row = $this->db->table('source_image_processings')->where('public_id', '=', $id)->first() ?? throw new HttpException(404, 'Not found');
        foreach (['peer_id', 'message_id', 'photo_id'] as $field) {
            if (!is_string($payload[$field] ?? null) && !is_int($payload[$field] ?? null)) {
                throw new HttpException(422, 'Invalid image identity');
            }
            $column = $field === 'message_id' ? 'telegram_message_id' : ($field === 'photo_id' ? 'telegram_photo_id' : $field);
            if ((string) ($payload[$field] ?? '') !== (string) $row[$column]) {
                throw new HttpException(422, 'Image identity mismatch');
            }
        }
        [$ctx, $source, $item] = $this->scope($row);
        if ($row['status'] !== 'queued') {
            return ['ack' => true, 'job_id' => $id, 'duplicate' => true, 'status' => (string) $row['status']];
        }
        if (!$source->enabled || !$this->current($ctx, $source, $item, (string) $row['revision_hash'], (string) $row['selection_hash'])) {
            $this->finish($row, 'stale', 'Материал или решение отбора изменились.');
            return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'stale'];
        }
        if (isset($payload['error'])) {
            $this->finish($row, 'failed', 'Не удалось получить изображение из Telegram. Повторите обработку.');
            return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'failed'];
        }
        $bytes = is_string($payload['data'] ?? null) ? base64_decode($payload['data'], true) : false;
        if ($bytes === false || strlen($bytes) > 16777216 || !is_string($payload['sha256'] ?? null) || !hash_equals(hash('sha256', $bytes), $payload['sha256'])) {
            throw new HttpException(422, 'Invalid image digest');
        }
        $paths = [];
        $variants = [];
        $committed = false;
        $durableKeys = [];
        try {
            $originalPath = $this->files->temporary($bytes);
            $paths[] = $originalPath;
            $original = $this->analysis->inspect($originalPath);
            $message = $this->db->table('source_messages')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $row['message_id'])->first();
            $media = json_decode((string) ($message['media_json'] ?? 'null'), true, 32, JSON_THROW_ON_ERROR);
            $best = is_array($media) ? ($media['selected'] ?? null) : null;
            // The reader selects the largest advertised PhotoSize. Reject accidentally delivered thumbnails.
            if (is_array($best) && isset($best['width'], $best['height']) && max($original['width'], $original['height']) * min($original['width'], $original['height']) < (int) $best['width'] * (int) $best['height']) {
                throw new HttpException(422, 'Image is smaller than best Telegram size');
            }
            $variants[] = $this->variant($ctx->workspaceId, $originalPath, $original, 'original', null, 'telegram', ['status' => 'original']);
            $warnings = [];
            if ($original['quality'] !== 'good') {
                foreach (['image_search', 'image_enhancement'] as $operation) {
                    $saved = $this->calls->imageResult($ctx->workspaceId, $id, $operation);
                    try {
                        if ($saved === null) {
                            if (!$this->calls->claimImage($ctx->workspaceId, $source->id, $id, $operation)) {
                                throw new \App\Integrations\ContentProviders\ProviderException('outcome_unavailable');
                            }
                            $saved = [];
                            if ($operation === 'image_search') {
                                foreach (array_slice($this->search->search($originalPath), 0, 5) as $candidate) {
                                    $path = $this->files->temporary($candidate->bytes);
                                    $paths[] = $path;
                                    try {
                                        $data = $this->analysis->inspect($path);
                                        if ($data['width'] * $data['height'] > $original['width'] * $original['height']) {
                                            $saved[] = $this->variant($ctx->workspaceId, $path, $data, 'candidate', $candidate->sourceUrl, $this->search->name(), $this->analysis->match($original, $data), $candidate->metadata);
                                        }
                                    } catch (\InvalidArgumentException|\App\Domain\Media\MediaException) {
                                        $warnings[] = 'Одна из найденных копий не прошла техническую проверку.';
                                    }
                                }
                            } else {
                                $enhanced = $this->enhancement->enhance($originalPath);
                                if ($enhanced !== null) {
                                    $path = $this->files->temporary($enhanced);
                                    $paths[] = $path;
                                    $data = $this->analysis->inspect($path);
                                    $saved[] = $this->variant($ctx->workspaceId, $path, $data, 'enhanced', null, $this->enhancement->name(), $this->analysis->match($original, $data), $this->enhancement instanceof \App\Integrations\ContentProviders\ProviderMetadata ? $this->enhancement->metadata() : []);
                                }
                            }
                            $this->calls->imageSuccess($ctx->workspaceId, $id, $operation, $saved);
                        }
                        foreach ($saved as $variant) {
                            $durableKeys[] = $variant['storage_key'];
                            $variants[] = $variant;
                        }
                    } catch (\App\Integrations\ContentProviders\ProviderException $e) {
                        $this->calls->imageFailure($ctx->workspaceId, $id, $operation, $e);
                        if ($e->retryable) {
                            throw new HttpException(503, 'Image provider temporarily unavailable');
                        }
                        $warnings[] = $operation === 'image_search' ? 'Поиск копий недоступен.' : 'Enhancement недоступен.';
                    } catch (\Throwable) {
                        $warnings[] = $operation === 'image_search' ? 'Поиск копий недоступен.' : 'Enhancement недоступен.';
                    }
                }
            }
            $response = $this->db->transaction(function () use ($row, $ctx, $source, $id, $variants, $warnings): array {
                $this->lock($ctx, $source);
                $fresh = $this->db->select('SELECT * FROM source_image_processings WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $row['id']])[0] ?? throw new HttpException(404, 'Not found');
                if ($fresh['status'] !== 'queued') {
                    return ['ack' => true, 'job_id' => $id, 'duplicate' => true, 'status' => (string) $fresh['status']];
                }
                $item = $this->db->table('source_items')->where('workspace_id', '=', $ctx->workspaceId)->where('source_id', '=', $source->id)->where('id', '=', $row['item_id'])->first() ?? throw new HttpException(404, 'Not found');
                $source = $this->sources->find($ctx, $source->publicId) ?? throw new HttpException(404, 'Not found');
                if (!$source->enabled || !$this->current($ctx, $source, $item, (string) $row['revision_hash'], (string) $row['selection_hash'])) {
                    $this->finish($row, 'stale', 'Материал или решение отбора изменились.');
                    return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'stale'];
                }
                $this->db->select('SELECT id FROM workspaces WHERE id = ? FOR UPDATE', [$ctx->workspaceId]);
                $used = (int) $this->db->select('SELECT COALESCE(SUM(bytes), 0) AS used FROM source_image_variants WHERE workspace_id = ?', [$ctx->workspaceId])[0]['used'];
                if ($used + array_sum(array_column($variants, 'bytes')) > 536870912) {
                    $this->finish($row, 'failed', 'Лимит хранилища обработки изображений: 512 МБ на workspace.');
                    return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'failed'];
                }
                foreach ($variants as $variant) {
                    $this->db->table('source_image_variants')->insert(array_merge($variant, ['workspace_id' => $ctx->workspaceId, 'processing_id' => $row['id'], 'created_at' => DbTime::format($this->clock->now())]));
                }
                $this->db->execute('UPDATE source_image_processings SET selected_variant = ? WHERE workspace_id = ? AND id = ?', [$variants[0]['public_id'], $ctx->workspaceId, $row['id']]);
                $this->finish($row, 'completed', $warnings === [] ? null : implode(' ', array_unique($warnings)));
                // Set after transaction commits, not inside it (audit failure must also clean archives).
                return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'completed'];
            });
            $committed = !$response['duplicate'] && $response['status'] === 'completed';
            return $response;
        } catch (\App\Domain\Media\MediaException|\InvalidArgumentException) {
            $this->finish($row, 'failed', 'Изображение не прошло техническую проверку.');
            return ['ack' => true, 'job_id' => $id, 'duplicate' => false, 'status' => 'failed'];
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable) {
            // Transient DB/storage failure must be retried by durable reader outbox; no ACK and no raw error/log.
            throw new HttpException(503, 'Image processing unavailable');
        } finally {
            foreach ($variants as $variant) {
                if (!$committed && !in_array($variant['storage_key'], $durableKeys, true)) {
                    $this->files->delete(['storage_key' => (string) $variant['storage_key'], 'preview_key' => (string) $variant['preview_key']]);
                }
            }
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $verification
     * @param array<string,mixed> $metadata
     * @return array<string,scalar|null>
     */
    private function variant(int $workspaceId, string $path, array $data, string $kind, ?string $url, string $provider, array $verification, array $metadata = []): array
    {
        return array_merge($this->files->save($workspaceId, $path), ['public_id' => (string) new Ulid(), 'kind' => $kind, 'width' => (int) $data['width'], 'height' => (int) $data['height'], 'mime' => (string) $data['mime'], 'bytes' => (int) $data['bytes'], 'quality' => (string) $data['quality'], 'metrics_json' => json_encode(array_merge($data['metrics'], ['perceptual_hash' => $data['hash']]), JSON_THROW_ON_ERROR), 'source_url' => $url, 'verification' => (string) $verification['status'], 'confidence' => isset($verification['confidence']) ? (float) $verification['confidence'] : null, 'verification_json' => json_encode($verification, JSON_THROW_ON_ERROR), 'provider' => $provider, 'provider_metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{WorkspaceContext,Source,array<string,mixed>}
     */
    private function scope(array $row): array
    {
        $workspace = $this->workspaces->findById((int) $row['workspace_id']) ?? throw new HttpException(404, 'Not found');
        $membership = $this->workspaces->membership($workspace->id, $workspace->ownerId) ?? throw new HttpException(404, 'Not found');
        $ctx = WorkspaceContext::from($workspace, $membership);
        $sourceId = $this->db->table('sources')->where('workspace_id', '=', $ctx->workspaceId)->where('id', '=', $row['source_id'])->first()['public_id'] ?? '';
        $source = $this->sources->find($ctx, (string) $sourceId) ?? throw new HttpException(404, 'Not found');
        $item = $this->db->table('source_items')->where('workspace_id', '=', $ctx->workspaceId)->where('source_id', '=', $source->id)->where('id', '=', $row['item_id'])->first() ?? throw new HttpException(404, 'Not found');
        return [$ctx, $source, $item];
    }

    /** @param array<string,mixed> $row */
    private function finish(array $row, string $status, ?string $error): void
    {
        $this->db->transaction(function () use ($row, $status, $error): void {
            $now = DbTime::format($this->clock->now());
            $changed = $this->db->execute("UPDATE source_image_processings SET status = ?, error = ?, updated_at = ?, finished_at = ? WHERE workspace_id = ? AND id = ? AND status = 'queued'", [$status, $error === null ? null : mb_substr($error, 0, 128), $now, $now, $row['workspace_id'], $row['id']]);
            if ($changed === 0) {
                return;
            }
            $this->audit->record('source.images_' . $status, $row['created_by'] === null ? null : (int) $row['created_by'], 'source_image', (string) $row['public_id'], [], (int) $row['workspace_id']);
        });
    }

    private function lock(WorkspaceContext $context, Source $source): void
    {
        if ($this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$context->workspaceId, $source->id]) === []) {
            throw new HttpException(404, 'Not found');
        }
    }
}
