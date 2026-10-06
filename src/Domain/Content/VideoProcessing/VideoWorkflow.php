<?php

declare(strict_types=1);

namespace App\Domain\Content\VideoProcessing;

use App\Domain\Audit\AuditLog;
use App\Domain\Content\ImageProcessing\ImageRepository;
use App\Domain\Content\Processing\MaterialRepository;
use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Integrations\Video\VideoInput;
use App\Integrations\Video\VideoProvider;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Independent durable video lifecycle: current approved snapshots, immutable bases and guarded final selection. */
final class VideoWorkflow
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly MaterialRepository $materials, private readonly ImageRepository $images, private readonly VideoRepository $repository, private readonly VideoProvider $provider, private readonly AuditLog $audit)
    {
    }

    /** @return array{item:array<string,mixed>,revision:string,selection_hash:string} */
    private function snapshot(WorkspaceContext $ctx, Source $source, string $itemId): array
    {
        $item = $this->materials->item($ctx, $source, $itemId);
        $selection = $this->materials->selection($ctx, $source, (int) $item['id']);
        if (($selection['selection_status'] ?? '') !== 'approved') {
            throw new HttpException(409, 'Сначала примите материал.');
        }
        return ['item' => $item, 'revision' => MaterialRepository::revision($item, $this->materials->messages($ctx, $source, (int) $item['id'])), 'selection_hash' => MaterialRepository::selectionHash($selection)];
    }

    /**
     * @param array{item:array<string,mixed>,revision:string,selection_hash:string} $snapshot
     * @return array{text:string,text_version:?string,image:?array<string,mixed>}
     */
    private function basis(WorkspaceContext $ctx, Source $source, array $snapshot, VideoSettings $settings): array
    {
        $text = (string) $snapshot['item']['text'];
        if ($settings->textVersion !== '') {
            $row = $this->db->select('SELECT * FROM source_text_processings WHERE workspace_id = ? AND source_id = ? AND item_id = ? AND public_id = ?', [$ctx->workspaceId, $source->id, $snapshot['item']['id'], $settings->textVersion])[0] ?? throw new HttpException(409, 'Выберите актуальную обработанную версию текста.');
            if (!$this->matches($row, $snapshot) || $row['status'] !== 'completed' || $row['processed_text'] === null) {
                throw new HttpException(409, 'Выберите актуальную обработанную версию текста.');
            }
            $text = (string) $row['processed_text'];
        }
        if (mb_strlen($text) > 100000) {
            throw new HttpException(422, 'Текст слишком длинный для генерации.');
        }
        $image = null;
        if ($settings->imageVersion !== '') {
            $variant = $this->images->variant($ctx, $source, (int) $snapshot['item']['id'], $settings->imageVersion);
            $selected = $this->db->select('SELECT selected_variant FROM source_image_processings WHERE workspace_id = ? AND source_id = ? AND item_id = ? AND public_id = ?', [$ctx->workspaceId, $source->id, $snapshot['item']['id'], $variant['processing_public_id']])[0]['selected_variant'] ?? null;
            if (!$this->matches($variant, $snapshot) || $variant['status'] !== 'completed' || $selected !== $settings->imageVersion) {
                throw new HttpException(409, 'Сначала выберите актуальное изображение в обработке изображений.');
            }
            $image = array_intersect_key($variant, array_flip(['public_id', 'storage_key', 'sha256', 'width', 'height', 'mime', 'kind']));
        }
        return ['text' => $text, 'text_version' => $settings->textVersion === '' ? null : $settings->textVersion, 'image' => $image];
    }

    /** Creates a pending attempt; never modifies a SourceItem or earlier result. */
    public function request(WorkspaceContext $ctx, Source $source, string $itemId, string $revision, VideoSettings $settings): string
    {
        return $this->db->transaction(function () use ($ctx, $source, $itemId, $revision, $settings): string {
            $this->lock($ctx, $source);
            $snapshot = $this->snapshot($ctx, $source, $itemId);
            if (!hash_equals($snapshot['revision'], $revision)) {
                throw new HttpException(409, 'Материал изменился. Обновите страницу.');
            }
            $basis = $this->basis($ctx, $source, $snapshot, $settings);
            $version = (int) $this->db->select('SELECT COALESCE(MAX(settings_version), 0) AS version FROM source_video_generations WHERE workspace_id = ? AND item_id = ?', [$ctx->workspaceId, $snapshot['item']['id']])[0]['version'] + 1;
            $provider = $this->provider->name();
            if (preg_match('/^[a-z0-9_-]{1,32}$/D', $provider) !== 1) {
                throw new HttpException(503, 'Провайдер генерации недоступен.');
            }
            $id = (string) new Ulid();
            $now = DbTime::format($this->clock->now());
            $this->db->table('source_video_generations')->insert(['public_id' => $id, 'workspace_id' => $ctx->workspaceId, 'source_id' => $source->id, 'item_id' => $snapshot['item']['id'], 'revision_hash' => $snapshot['revision'], 'selection_hash' => $snapshot['selection_hash'], 'settings_version' => $version, 'settings_json' => json_encode($settings->form(), JSON_THROW_ON_ERROR), 'basis_json' => json_encode($basis, JSON_THROW_ON_ERROR), 'status' => 'pending', 'provider' => $provider, 'created_by' => $ctx->userId, 'created_at' => $now, 'updated_at' => $now]);
            $this->audit->record('source.video_requested', $ctx->userId, 'source_item', $itemId, ['version' => $version], $ctx->workspaceId);
            return $id;
        });
    }

    /**
     * Atomically claims pending work; duplicate execution cannot invoke the provider twice.
     *
     * @phpstan-impure
     */
    public function run(WorkspaceContext $ctx, Source $source, string $itemId, string $jobId): string
    {
        $attempt = $this->db->transaction(function () use ($ctx, $source, $itemId, $jobId): array {
            $this->lock($ctx, $source);
            $item = $this->materials->item($ctx, $source, $itemId);
            $row = $this->repository->attempt($ctx, $source, (int) $item['id'], $jobId);
            if ($row['status'] !== 'pending') {
                return $row;
            }
            if (!$this->current($ctx, $source, $itemId, $row)) {
                $this->finish($ctx, $source, $itemId, $jobId, null, 'Материал, решение отбора или основа изменились. Создайте новое задание.');
                $row['status'] = 'failed';
                return $row;
            }
            if ($row['provider'] !== $this->provider->name()) {
                $this->finish($ctx, $source, $itemId, $jobId, null, 'Провайдер изменился. Создайте новое задание.');
                $row['status'] = 'failed';
                return $row;
            }
            $now = DbTime::format($this->clock->now());
            $this->db->execute('UPDATE source_video_generations SET status = ?, started_at = ?, updated_at = ? WHERE workspace_id = ? AND public_id = ?', ['processing', $now, $now, $ctx->workspaceId, $jobId]);
            $this->audit->record('source.video_started', $ctx->userId, 'source_item', $itemId, [], $ctx->workspaceId);
            $row['claimed'] = true;
            return $row;
        });
        if (!isset($attempt['claimed'])) {
            return (string) $attempt['status'];
        }
        $result = null;
        $error = null;
        try {
            $settings = VideoSettings::fromInput(json_decode((string) $attempt['settings_json'], true, 32, JSON_THROW_ON_ERROR));
            $basis = json_decode((string) $attempt['basis_json'], true, 32, JSON_THROW_ON_ERROR);
            $result = $this->provider->generate(new VideoInput($jobId, $settings->aspectRatio, $settings->duration, $settings->instruction, (string) $basis['text'], $basis['image']))->snapshot();
        } catch (Throwable) {
            $error = 'Генерация не удалась. Создайте новое задание и повторите попытку.';
        }
        return $this->db->transaction(function () use ($ctx, $source, $itemId, $jobId, $attempt, $result, $error): string {
            $this->lock($ctx, $source);
            if (!$this->current($ctx, $source, $itemId, $attempt)) {
                $result = null;
                $error = 'Материал, решение отбора или основа изменились. Создайте новое задание.';
            }
            return $this->finish($ctx, $source, $itemId, $jobId, $result, $error);
        });
    }

    /** @param array<string,mixed> $row */
    public function current(WorkspaceContext $ctx, Source $source, string $itemId, array $row): bool
    {
        try {
            $snapshot = $this->snapshot($ctx, $source, $itemId);
            return $this->matches($row, $snapshot) && $this->canonical($this->basis($ctx, $source, $snapshot, VideoSettings::fromInput(json_decode((string) $row['settings_json'], true, 32, JSON_THROW_ON_ERROR)))) === $this->canonical(json_decode((string) $row['basis_json'], true, 32, JSON_THROW_ON_ERROR));
        } catch (HttpException) {
            return false;
        }
    }

    /** Selects one completed current attempt per item, including explicitly labelled fake demonstrations. */
    public function choose(WorkspaceContext $ctx, Source $source, string $itemId, string $jobId): void
    {
        $this->db->transaction(function () use ($ctx, $source, $itemId, $jobId): void {
            $this->lock($ctx, $source);
            $snapshot = $this->snapshot($ctx, $source, $itemId);
            $row = $this->repository->attempt($ctx, $source, (int) $snapshot['item']['id'], $jobId);
            if ($row['status'] !== 'completed' || !$this->current($ctx, $source, $itemId, $row)) {
                throw new HttpException(409, 'Выберите готовый результат для актуального материала.');
            }
            $now = DbTime::format($this->clock->now());
            $this->db->execute('UPDATE source_video_generations SET selected = FALSE WHERE workspace_id = ? AND source_id = ? AND item_id = ?', [$ctx->workspaceId, $source->id, $snapshot['item']['id']]);
            $this->db->execute('UPDATE source_video_generations SET selected = TRUE, updated_at = ? WHERE workspace_id = ? AND public_id = ?', [$now, $ctx->workspaceId, $jobId]);
            $this->audit->record('source.video_selected', $ctx->userId, 'source_item', $itemId, [], $ctx->workspaceId);
        });
    }

    /** @param array<string,mixed>|null $result */
    private function finish(WorkspaceContext $ctx, Source $source, string $itemId, string $jobId, ?array $result, ?string $error): string
    {
        $status = $error === null ? 'completed' : 'failed';
        $now = DbTime::format($this->clock->now());
        $this->db->execute('UPDATE source_video_generations SET status = ?, result_json = ?, error = ?, finished_at = ?, updated_at = ? WHERE workspace_id = ? AND source_id = ? AND public_id = ?', [$status, $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR), $error, $now, $now, $ctx->workspaceId, $source->id, $jobId]);
        $this->audit->record('source.video_' . $status, $ctx->userId, 'source_item', $itemId, [], $ctx->workspaceId);
        return $status;
    }

    /**
     * @param array<string,mixed> $row
     * @param array{item:array<string,mixed>,revision:string,selection_hash:string} $snapshot
     */
    private function matches(array $row, array $snapshot): bool
    {
        return $row['revision_hash'] === $snapshot['revision'] && $row['selection_hash'] === $snapshot['selection_hash'];
    }
    /** @param array<string,mixed> $basis */
    private function canonical(array $basis): string
    {
        // MySQL JSON reorders object keys; compare normalized snapshots without coercing scalar types.
        ksort($basis);
        if (is_array($basis['image'] ?? null)) {
            ksort($basis['image']);
        }
        return json_encode($basis, JSON_THROW_ON_ERROR);
    }
    private function lock(WorkspaceContext $ctx, Source $source): void
    {
        if ($this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$ctx->workspaceId, $source->id]) === []) {
            throw new HttpException(404, 'Not found');
        }
    }
}
