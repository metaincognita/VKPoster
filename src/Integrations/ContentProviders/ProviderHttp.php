<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

use App\Kernel\HttpClient\HttpClientInterface;
use Closure;

/** Bounded JSON transport. Ambiguous paid POSTs are never replayed; only explicit 429 is retried. */
final class ProviderHttp
{
    /** @var Closure(int):void */
    private readonly Closure $sleep;
    /**
     * @param (Closure(int):void)|null $sleep Delay in microseconds; tests inject a no-op. */
    public function __construct(private readonly HttpClientInterface $http, ?Closure $sleep = null)
    {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function json(string $method, string $url, array $options): array
    {
        for ($attempt = 0; $attempt < 3; ++$attempt) {
            try {
                if ($attempt > 0 && is_array($options['multipart'] ?? null)) {
                    foreach ($options['multipart'] as $part) {
                        if (is_array($part) && is_resource($part['contents'] ?? null) && !rewind($part['contents'])) {
                            throw new ProviderException('upload_retry_failed');
                        }
                    }
                }
                $response = $this->http->request($method, $url, array_merge($options, ['timeout' => 20, 'connect_timeout' => 5, 'follow_redirects' => false, 'stream' => true]));
            } catch (\Throwable) {
                if ($method === 'GET' && $attempt < 2) {
                    ($this->sleep)(250000 * ($attempt + 1));
                    continue;
                }
                throw new ProviderException('transport_failed', $method === 'GET');
            }
            $status = $response->getStatusCode();
            if ($status === 429 || ($method === 'GET' && in_array($status, [500, 502, 503, 504], true))) {
                $response->getBody()->close();
                if ($attempt < 2) {
                    $retry = $response->getHeaderLine('Retry-After');
                    // Long delays must be retried by a later job, not by sleeping in an HTTP request.
                    if (ctype_digit($retry) && (int) $retry > 2) {
                        throw new ProviderException('rate_limited', true);
                    }
                    ($this->sleep)(ctype_digit($retry) ? (int) $retry * 1000000 : 250000 * ($attempt + 1));
                    continue;
                }
                throw new ProviderException($status === 429 ? 'rate_limited' : 'unavailable', true);
            }
            if ($status < 200 || $status >= 300) {
                $response->getBody()->close();
                throw new ProviderException($status === 401 || $status === 403 ? 'credentials_or_access' : 'request_failed');
            }
            $body = '';
            try {
                while (!$response->getBody()->eof()) {
                    $body .= $response->getBody()->read(8192);
                    if (strlen($body) > 2097152) {
                        throw new ProviderException('response_too_large');
                    }
                }
                $result = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            } catch (ProviderException $e) {
                throw $e;
            } catch (\Throwable) {
                throw new ProviderException('invalid_response');
            } finally {
                $response->getBody()->close();
            }
            if (!is_array($result) || array_is_list($result)) {
                throw new ProviderException('invalid_response');
            }
            return $result;
        }
        throw new ProviderException('unavailable', true);
    }
}
