<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

/** Replicate HTTP API with pinned enhancement model and opaque durable prediction IDs. */
final class ReplicateApi
{
    public function __construct(private readonly ProviderHttp $http, private readonly string $token)
    {
    }
    /**
     * @param array<string,mixed>|null $input
     * @return array<string,mixed> */
    public function call(string $method, string $path, ?array $input = null, int $deadline = 600): array
    {
        if ($this->token === '') {
            throw new ProviderException('not_configured');
        }
        if (preg_match('~^/(?:predictions(?:/[a-z0-9]{1,64}(?:/cancel)?)?|models/[a-z0-9_-]+/[a-z0-9_-]+/predictions)$~D', $path) !== 1) {
            throw new ProviderException('invalid_endpoint');
        }
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->token, 'Cancel-After' => (string) $deadline . 's']];
        if ($input !== null) {
            $options['json'] = $input;
        }
        return $this->http->json($method, 'https://api.replicate.com/v1' . $path, $options);
    }
    /** Uploads the original bytes privately rather than exposing a VKPoster URL or resizing the source. */
    public function upload(string $bytes, string $mime): string
    {
        if ($this->token === '' || strlen($bytes) > 16777216 || $bytes === '' || !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new ProviderException('invalid_upload');
        }
        $info = @getimagesizefromstring($bytes);
        if ($info === false || $info['mime'] !== $mime || $info[0] * $info[1] > 40000000) {
            throw new ProviderException('invalid_upload');
        }
        $row = $this->http->json('POST', 'https://api.replicate.com/v1/files', ['headers' => ['Authorization' => 'Bearer ' . $this->token], 'multipart' => [['name' => 'content', 'contents' => $bytes, 'filename' => 'basis', 'headers' => ['Content-Type' => $mime]]]]);
        $url = is_array($row['urls'] ?? null) ? ($row['urls']['get'] ?? null) : null;
        if (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !(str_ends_with((string) parse_url($url, PHP_URL_HOST), '.replicate.delivery') || (parse_url($url, PHP_URL_HOST) === 'api.replicate.com' && preg_match('~^/v1/files/[a-z0-9]+$~D', (string) parse_url($url, PHP_URL_PATH)) === 1))) {
            throw new ProviderException('invalid_upload_response');
        }
        return $url;
    }
    /**
     * @param array<string,mixed> $row */
    public static function id(array $row): string
    {
        $id = $row['id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-z0-9]{1,64}$/D', $id) !== 1) {
            throw new ProviderException('invalid_prediction_id');
        }
        return $id;
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> */
    public static function metadata(array $row): array
    {
        $metadata = [];
        if (is_string($row['version'] ?? null) && preg_match('/^[a-zA-Z0-9_\/-]{1,160}$/D', $row['version']) === 1) {
            $metadata['model_version'] = $row['version'];
        }
        if (is_array($row['metrics'] ?? null)) {
            foreach (['predict_time', 'total_time'] as $key) {
                $value = $row['metrics'][$key] ?? null;
                if ((is_float($value) || is_int($value)) && is_finite((float) $value) && $value >= 0) {
                    $metadata[$key . '_ms'] = (int) round($value * 1000);
                }
            }
        }
        return $metadata;
    }
}
