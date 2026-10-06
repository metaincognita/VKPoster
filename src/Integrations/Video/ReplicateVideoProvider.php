<?php

declare(strict_types=1);

namespace App\Integrations\Video;

use App\Integrations\ContentProviders\ReplicateApi;
use App\Integrations\ContentProviders\SafeDownloads;
use App\Integrations\ContentProviders\ProviderMetadata;
use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\Storage\MediaStorage;
use App\Domain\Media\VideoProbe;

/** Seedance HTTP adapter: durable remote jobs and privately archived, probed MP4 output. */
final class ReplicateVideoProvider implements AsyncVideoProvider, ProviderMetadata
{
    /** @var array<string,mixed> */
    private array $details = [];
    public function __construct(private readonly ReplicateApi $api, private readonly SafeDownloads $downloads, private readonly MediaStorage $storage, private readonly VideoProbe $probe)
    {
    }
    public function name(): string
    {
        return 'replicate_seedance';
    }
    public function generate(VideoInput $input): VideoResult
    {
        throw new ProviderException('async_provider_required');
    }
    public function start(VideoInput $input): string
    {
        $this->details = ['model' => 'bytedance/seedance-1-pro'];
        if ($input->duration < 2 || $input->duration > 12 || !in_array($input->aspectRatio, ['16:9', '9:16', '1:1'], true) || mb_strlen($input->instruction . $input->text) > 12000) {
            throw new ProviderException('unsupported_video_settings');
        }
        $parameters = ['prompt' => trim($input->instruction . "\n" . $input->text), 'duration' => $input->duration, 'aspect_ratio' => $input->aspectRatio, 'resolution' => '480p', 'fps' => 24];
        if ($parameters['prompt'] === '') {
            throw new ProviderException('empty_video_prompt');
        }
        if ($input->image !== null) {
            $key = $input->image['storage_key'] ?? null;
            $mime = $input->image['mime'] ?? null;
            [$w, $h] = array_map('intval', explode(':', $input->aspectRatio));
            if (!is_string($key) || !is_string($mime) || !is_int($input->image['width'] ?? null) || !is_int($input->image['height'] ?? null) || abs($input->image['width'] / max(1, $input->image['height']) - $w / $h) > 0.03 || $this->storage->size($key) > 16777216) {
                throw new ProviderException('unsupported_image_basis');
            }
            $stream = $this->storage->read($key);
            try {
                $bytes = stream_get_contents($stream, 16777217);
            } finally {
                fclose($stream);
            }
            if ($bytes === false) {
                throw new ProviderException('invalid_image_basis');
            }
            $parameters['image'] = $this->api->upload($bytes, $mime);
        }
        $row = $this->api->call('POST', '/models/bytedance/seedance-1-pro/predictions', ['input' => $parameters]);
        $this->details = array_merge($this->details, ReplicateApi::metadata($row));
        return ReplicateApi::id($row);
    }
    public function poll(string $providerJobId, string $localJobId): ?VideoResult
    {
        if (preg_match('/^[a-z0-9]{1,64}$/D', $providerJobId) !== 1 || preg_match('/^[0-9A-Z]{26}$/D', $localJobId) !== 1) {
            throw new ProviderException('invalid_video_job');
        }
        $row = $this->api->call('GET', '/predictions/' . $providerJobId);
        $this->details = array_merge(['model' => 'bytedance/seedance-1-pro'], ReplicateApi::metadata($row));
        if (ReplicateApi::id($row) !== $providerJobId) {
            throw new ProviderException('prediction_mismatch');
        }
        if (in_array($row['status'] ?? '', ['starting', 'processing'], true)) {
            return null;
        }
        if (($row['status'] ?? '') !== 'succeeded' || !is_string($row['output'] ?? null)) {
            throw new ProviderException('video_failed');
        }
        $bytes = $this->downloads->bytes($row['output'], 52428800, ['video/mp4']);
        $path = tempnam(sys_get_temp_dir(), 'provider-video-');
        if ($path === false) {
            throw new ProviderException('archive_failed');
        }
        try {
            if (file_put_contents($path, $bytes) !== strlen($bytes)) {
                throw new ProviderException('archive_failed');
            }
            $info = $this->probe->probe($path);
            if ($info->durationMs < 1000 || $info->durationMs > 15000 || $info->width * $info->height > 8300000) {
                throw new ProviderException('invalid_video_output');
            }
            $this->details += ['width' => $info->width, 'height' => $info->height, 'duration_ms' => $info->durationMs, 'bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
            $stream = fopen($path, 'rb');
            if ($stream === false) {
                throw new ProviderException('archive_failed');
            }
            $key = 'source-videos/' . $localJobId . '/' . hash('sha256', $bytes);
            try {
                $this->storage->put($key, $stream);
            } finally {
                fclose($stream);
            }
            return new VideoResult(false, $key);
        } finally {
            unlink($path);
        }
    }
    public function metadata(): array
    {
        return $this->details;
    }
}
