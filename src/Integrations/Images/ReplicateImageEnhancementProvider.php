<?php

declare(strict_types=1);

namespace App\Integrations\Images;

use App\Integrations\ContentProviders\ReplicateApi;
use App\Integrations\ContentProviders\SafeDownloads;
use App\Integrations\ContentProviders\ProviderMetadata;
use App\Integrations\ContentProviders\ProviderException;
use Closure;

/** Portable Real-ESRGAN model behind the existing enhancement interface; bounded prediction with cancellation. */
final class ReplicateImageEnhancementProvider implements ImageEnhancementProvider, ProviderMetadata
{
    public const VERSION = 'e1f4a2081605342caf55ba4294914cf266dcdf738397cf8826b48cdae516137c';
    /** @var array<string,mixed> */
    private array $details = [];
    /** @var Closure(int):void */
    private readonly Closure $sleep;
    private readonly \App\Support\Clock $clock;
    /** @param (Closure(int):void)|null $sleep */
    public function __construct(private readonly ReplicateApi $api, private readonly SafeDownloads $downloads, ?Closure $sleep = null, ?\App\Support\Clock $clock = null)
    {
        $this->clock = $clock ?? new \App\Support\SystemClock();
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }
    public function name(): string
    {
        return 'replicate_esrgan';
    }
    public function enhance(string $originalPath): string
    {
        $this->details = ['model' => 'nightmareai/real-esrgan', 'model_version' => self::VERSION];
        $size = filesize($originalPath);
        if ($size === false || $size > 16777216 || $size < 1) {
            throw new ProviderException('invalid_image_input');
        }
        $bytes = file_get_contents($originalPath);
        if ($bytes === false) {
            throw new ProviderException('invalid_image_input');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $url = $this->api->upload($bytes, (string) $mime);
        $row = $this->api->call('POST', '/predictions', ['version' => self::VERSION, 'input' => ['image' => $url, 'scale' => 2, 'face_enhance' => false]], 30);
        $id = ReplicateApi::id($row);
        $this->details['provider_job_id'] = $id;
        $deadline = $this->clock->now()->modify('+30 seconds');
        $terminal = false;
        try {
            // Model execution is limited remotely to 30 seconds; never leave expensive orphan work on timeout.
            while (true) {
                if (ReplicateApi::id($row) !== $id) {
                    throw new ProviderException('prediction_mismatch');
                }
                $this->details = array_merge($this->details, ReplicateApi::metadata($row));
                $terminal = in_array($row['status'] ?? '', ['succeeded', 'failed', 'canceled', 'cancelled'], true);
                if (($row['status'] ?? '') === 'succeeded') {
                    if (!is_string($row['output'] ?? null)) {
                        throw new ProviderException('invalid_enhancement_output');
                    }
                    return $this->downloads->bytes($row['output'], 16777216, ['image/jpeg', 'image/png', 'image/webp']);
                }
                if (!in_array($row['status'] ?? '', ['starting', 'processing'], true)) {
                    throw new ProviderException('enhancement_failed');
                }
                if ($this->clock->now() >= $deadline) {
                    throw new ProviderException('enhancement_timeout');
                }
                ($this->sleep)(1000000);
                $row = $this->api->call('GET', '/predictions/' . $id);
            }
        } catch (\Throwable $e) {
            if (!$terminal) {
                try {
                    $this->api->call('POST', '/predictions/' . $id . '/cancel');
                } catch (\Throwable) {
                }
            }
            // A remote prediction already exists: retrying the whole operation could charge twice.
            if ($e instanceof ProviderException && $e->retryable) {
                throw new ProviderException('enhancement_outcome_unknown');
            }
            throw $e;
        }
    }
    public function metadata(): array
    {
        return $this->details;
    }
}
