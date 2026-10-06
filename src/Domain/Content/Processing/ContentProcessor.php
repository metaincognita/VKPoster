<?php

declare(strict_types=1);

namespace App\Domain\Content\Processing;

use App\Domain\Audit\AuditLog;
use App\Domain\Source\Source;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use App\Kernel\Exception\HttpException;
use App\Support\Clock;
use App\Support\DbTime;
use Symfony\Component\Uid\Ulid;
use Throwable;

/** Durable attempt boundary: approve/current revision guards, short transactions around provider calls, retained history. */
final class ContentProcessor
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly MaterialRepository $materials, private readonly TextProcessor $text, private readonly AuditLog $audit)
    {
    }

    /** Processes only the requested current approved snapshot; every retry appends a new settings version. */
    public function process(WorkspaceContext $context, ?Source $source, string $itemPublicId, string $revision, TextSettings $settings): string
    {
        $attempt = $this->db->transaction(function () use ($context, $source, $itemPublicId, $revision, $settings): array {
            $this->lock($context, $source, $itemPublicId);
            $item = $this->materials->item($context, $source, $itemPublicId);
            $messages = $this->materials->messages($context, $source, (int) $item['id']);
            $selection = $this->materials->selection($context, $source, (int) $item['id']);
            if (($selection['selection_status'] ?? '') !== 'approved') {
                throw new HttpException(409, 'Сначала примите материал.');
            }
            $current = MaterialRepository::revision($item, $messages);
            if (!hash_equals($current, $revision)) {
                throw new HttpException(409, 'Материал изменился. Обновите страницу.');
            }
            if (mb_strlen((string) $item['text']) > 100000) {
                throw new HttpException(422, 'Текст слишком длинный для обработки.');
            }
            $version = (int) $this->db->select('SELECT COALESCE(MAX(settings_version), 0) AS version FROM source_text_processings WHERE workspace_id = ? AND item_id = ?', [$context->workspaceId, $item['id']])[0]['version'] + 1;
            $publicId = (string) new Ulid();
            $now = DbTime::format($this->clock->now());
            $selectionHash = MaterialRepository::selectionHash($selection);
            $this->db->table('source_text_processings')->insert([
                'public_id' => $publicId, 'workspace_id' => $context->workspaceId, 'source_id' => $source?->id, 'item_id' => $item['id'],
                'revision_hash' => $current, 'selection_hash' => $selectionHash, 'original_text' => $item['text'], 'mode' => $settings->mode,
                'settings_version' => $version, 'settings_json' => json_encode($settings->form(), JSON_THROW_ON_ERROR), 'status' => 'processing',
                'provider' => $this->text->providerName($settings), 'created_by' => $context->userId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record('source.text_started', $context->userId, 'source_item', $itemPublicId, ['mode' => $settings->mode, 'version' => $version], $context->workspaceId);
            return ['public_id' => $publicId, 'item' => $item, 'messages' => $messages, 'selection_hash' => $selectionHash];
        });
        // No source/database lock is held during network work. No exception detail or free text is logged.
        $output = null;
        $error = null;
        $category = null;
        $retryable = false;
        try {
            $output = $this->text->process((string) $attempt['item']['text'], $attempt['messages'], $source === null ? '' : $source->telegramUsername, $settings);
        } catch (\App\Integrations\ContentProviders\ProviderException $e) {
            $category = $e->category;
            $retryable = $e->retryable;
            $error = $retryable ? 'Временная ошибка. Повторная попытка будет выполнена автоматически.' : 'Обработка не подтверждена. Проверьте provider перед повтором.';
        } catch (Throwable) {
            $category = 'outcome_unknown';
            $error = 'Обработка не удалась. Повторите попытку.';
        }
        $metadata = $this->text->providerMetadata($settings);
        return $this->db->transaction(function () use ($context, $source, $itemPublicId, $revision, $attempt, $output, $error, $metadata, $category, $retryable): string {
            $this->lock($context, $source, $itemPublicId);
            $item = $this->materials->item($context, $source, $itemPublicId);
            $messages = $this->materials->messages($context, $source, (int) $item['id']);
            $selection = $this->materials->selection($context, $source, (int) $item['id']);
            $stale = !hash_equals($revision, MaterialRepository::revision($item, $messages)) || !hash_equals($attempt['selection_hash'], MaterialRepository::selectionHash($selection)) || ($selection['selection_status'] ?? '') !== 'approved';
            $prior = $this->db->select('SELECT status FROM source_text_processings WHERE workspace_id=? AND public_id=? FOR UPDATE', [$context->workspaceId, $attempt['public_id']])[0];
            if ($prior['status'] !== 'processing') {
                return (string) $prior['status'];
            }
            $status = $stale ? 'stale' : ($error === null ? 'completed' : 'failed');
            $now = DbTime::format($this->clock->now());
            $this->db->execute('UPDATE source_text_processings SET error_category = ?, retryable = ?, provider_metadata_json = ?, processed_text = ?, status = ?, error = ?, updated_at = ?, finished_at = ? WHERE workspace_id = ? AND source_id <=> ? AND public_id = ?', [$category, $retryable, json_encode($metadata, JSON_THROW_ON_ERROR), $output, $status, $stale ? 'Материал или решение отбора изменились. Обработайте актуальную версию.' : $error, $now, $now, $context->workspaceId, $source?->id, $attempt['public_id']]);
            $this->audit->record('source.text_' . $status, $context->userId, 'source_item', $itemPublicId, [], $context->workspaceId);
            return $status;
        });
    }

    private function lock(WorkspaceContext $context, ?Source $source, string $itemPublicId): void
    {
        if ($source === null) {
            $item = $this->materials->item($context, null, $itemPublicId);
            $this->db->select('SELECT id FROM source_items WHERE workspace_id = ? AND id = ? FOR UPDATE', [$context->workspaceId, $item['id']]);
            return;
        }
        if ($this->db->select('SELECT id FROM sources WHERE workspace_id = ? AND id = ? FOR UPDATE', [$context->workspaceId, $source->id]) === []) {
            throw new HttpException(404, 'Not found');
        }
    }
}
