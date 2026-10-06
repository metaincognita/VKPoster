<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

use App\Kernel\HttpClient\HttpClientInterface;
use App\Kernel\HttpClient\SsrfGuard;
use Psr\Http\Message\ResponseInterface;

/** Unauthenticated HTTPS media downloads: DNS guard/pinning with redirects rejected, bounded streaming, MIME and magic validation. */
final class SafeDownloads
{
    public function __construct(private readonly HttpClientInterface $http, private readonly SsrfGuard $guard)
    {
    }
    /** @param list<string> $mimes */
    public function bytes(string $url, int $limit, array $mimes): string
    {
        if ($limit < 1 || $limit > 52428800) {
            throw new ProviderException('invalid_download_limit');
        }
        if (strlen($url) > 8192 || parse_url($url, PHP_URL_SCHEME) !== 'https' || (parse_url($url, PHP_URL_PORT) ?? 443) !== 443) {
            throw new ProviderException('unsafe_download');
        }
        try {
            $this->guard->assertSafe($url);
            $response = $this->http->request('GET', $url, [
                'user_url' => true, 'follow_redirects' => false, 'timeout' => 15, 'connect_timeout' => 5, 'stream' => true,
                'on_headers' => static function (ResponseInterface $r) use ($limit, $mimes): void {
                    if ($r->getStatusCode() >= 300 && $r->getStatusCode() < 400) {
                        return;
                    }
                    self::headers($r, $limit, $mimes);
                },
                'progress' => static function (float $total, float $downloaded, float $uploadTotal, float $uploaded) use ($limit): void {
                    if ($downloaded > $limit || $total > $limit) {
                        throw new ProviderException('download_too_large');
                    }
                },
            ]);
            self::headers($response, $limit, $mimes);
            $bytes = '';
            try {
                while (!$response->getBody()->eof()) {
                    $bytes .= $response->getBody()->read(8192);
                    if (strlen($bytes) > $limit) {
                        throw new ProviderException('download_too_large');
                    }
                }
            } finally {
                $response->getBody()->close();
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            if ($bytes === '' || !in_array($mime, $mimes, true)) {
                throw new ProviderException('invalid_download_content');
            }
            if (str_starts_with($mime, 'image/')) {
                $info = @getimagesizefromstring($bytes);
                if ($info === false || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000 || $info['mime'] !== $mime) {
                    throw new ProviderException('invalid_image');
                }
            }
            return $bytes;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ProviderException('download_failed');
        } finally {
            if (isset($response)) {
                $response->getBody()->close();
            }
        }
    }
    /** @param list<string> $mimes */
    private static function headers(ResponseInterface $response, int $limit, array $mimes): void
    {
        $mime = strtolower(trim(explode(';', $response->getHeaderLine('Content-Type'))[0]));
        $length = $response->getHeaderLine('Content-Length');
        if ($response->getStatusCode() !== 200 || !in_array($mime, $mimes, true)) {
            throw new ProviderException('invalid_download_headers');
        }
        if ($length !== '' && (!ctype_digit($length) || (int) $length > $limit)) {
            throw new ProviderException('download_too_large');
        }
    }
}
