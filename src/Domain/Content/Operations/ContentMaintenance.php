<?php

declare(strict_types=1);

namespace App\Domain\Content\Operations;

use App\Integrations\Storage\MediaStorage;
use App\Kernel\Config;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;

/** Bounded recovery and opt-in orphan cleanup. Histories, selected results, originals and delivery identities are retained. */
final class ContentMaintenance
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock, private readonly Config $config, private readonly MediaStorage $storage)
    {
    }

    /** No ambiguous provider start is replayed; async video with a durable remote ID stays pollable.
     * @return array<string,int> */
    public function recover(): array
    {
        $now = DbTime::format($this->clock->now());
        $cutoff = DbTime::format($this->clock->now()->modify('-' . $this->config->int('content_operations.stuck_seconds', 3600) . ' seconds'));
        $text = $this->db->execute("UPDATE source_text_processings SET status='failed', error=?, updated_at=?, finished_at=? WHERE status='processing' AND updated_at<? LIMIT 100", ['Попытка прервана. Проверьте результат перед повторной обработкой.', $now, $now, $cutoff]);
        $video = $this->db->execute("UPDATE source_video_generations SET status='failed', error=?, updated_at=?, finished_at=? WHERE (status='processing' AND provider_job_id IS NULL AND updated_at<?) OR (status='pending' AND created_at<?) LIMIT 100", ['Запуск не подтверждён. Проверьте задание у провайдера.', $now, $now, $cutoff, DbTime::format($this->clock->now()->modify('-24 hours'))]);
        $images = $this->db->execute("UPDATE source_image_processings SET status='failed', error=?, updated_at=?, finished_at=? WHERE status='queued' AND updated_at<? LIMIT 100", ['Время ожидания reader превышено. Проверьте подключение источника.', $now, $now, DbTime::format($this->clock->now()->modify('-24 hours'))]);
        return ['text' => $text, 'video' => $video, 'images' => $images];
    }

    /** Dry-run is the default; at most 100 orphan objects and 100 old temporary files per pass.
     * @return array<string,int> */
    public function prune(bool $apply = false): array
    {
        $cutoff = $this->clock->now()->modify('-' . $this->config->int('content_operations.retention_days', 30) . ' days');
        $remove = $apply && $this->config->bool('content_operations.retention_enabled');
        $counts = ['objects' => 0, 'temporary' => 0, 'deleted' => 0];
        $rows = $this->db->select("SELECT o.storage_key FROM content_storage_objects o WHERE o.deleted_at IS NULL AND o.created_at<? AND NOT EXISTS (SELECT 1 FROM source_image_variants v WHERE v.storage_key=o.storage_key OR v.preview_key=o.storage_key) AND NOT EXISTS (SELECT 1 FROM source_video_generations g WHERE JSON_UNQUOTE(JSON_EXTRACT(g.result_json, '$.storage_key'))=o.storage_key OR JSON_UNQUOTE(JSON_EXTRACT(g.basis_json, '$.image.storage_key'))=o.storage_key) ORDER BY o.created_at LIMIT 100", [DbTime::format($cutoff)]);
        foreach ($rows as $row) {
            $this->db->transaction(function () use ($row, $cutoff, $remove, &$counts): void {
                $key = (string) $row['storage_key'];
                $object = $this->db->select('SELECT * FROM content_storage_objects WHERE storage_key=? FOR UPDATE', [$key])[0] ?? null;
                if ($object === null || $object['deleted_at'] !== null || (string) $object['created_at'] >= DbTime::format($cutoff)) {
                    return;
                }
                // Every reference is a pin, including stale history and video bases. Unknown legacy objects are never scanned.
                $image = $this->db->select('SELECT id FROM source_image_variants WHERE storage_key=? OR preview_key=? LIMIT 1', [$key, $key]);
                $video = $this->db->select("SELECT id FROM source_video_generations WHERE JSON_UNQUOTE(JSON_EXTRACT(result_json, '$.storage_key'))=? OR JSON_UNQUOTE(JSON_EXTRACT(basis_json, '$.image.storage_key'))=? LIMIT 1", [$key, $key]);
                if ($image !== [] || $video !== []) {
                    return;
                }
                ++$counts['objects'];
                if ($remove) {
                    $this->storage->delete($key);
                    $this->db->execute('UPDATE content_storage_objects SET deleted_at=? WHERE storage_key=?', [DbTime::format($this->clock->now()), $key]);
                    ++$counts['deleted'];
                }
            });
        }
        foreach (['source-image-', 'source-preview-', 'provider-video-'] as $prefix) {
            foreach ((($matches = glob(sys_get_temp_dir() . '/' . $prefix . '*')) === false ? [] : $matches) as $path) {
                if ($counts['temporary'] >= 100) {
                    break 2;
                }
                if (is_link($path) || !is_file($path) || (int) filemtime($path) >= $cutoff->getTimestamp()) {
                    continue;
                }
                ++$counts['temporary'];
                if ($remove && unlink($path)) {
                    ++$counts['deleted'];
                }
            }
        }
        return $counts;
    }

    /** Scheduler runs recovery hourly; destructive cleanup requires explicit environment opt-in. */
    public function tick(): void
    {
        $this->recover();
        $this->prune(true);
    }
}
