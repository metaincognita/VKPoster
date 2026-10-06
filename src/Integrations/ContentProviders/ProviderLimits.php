<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

use App\Kernel\Config;
use App\Support\Clock;

/** Redis atomic request budget, expiring concurrency leases and short circuit breaker; Redis failure denies costly calls. */
final class ProviderLimits
{
    public function __construct(private readonly \Redis $redis, private readonly Config $config, private readonly Clock $clock)
    {
    }

    /** @return array{key:string,token:string} No URLs, credentials or content are stored in Redis. */
    public function acquire(string $method, string $url): array
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $vendor = match ($host) {
            'api.openai.com' => 'openai', 'api.replicate.com' => 'replicate', 'api.tineye.com' => 'tineye', default => throw new ProviderException('invalid_provider_host'),
        };
        $key = 'content-provider:' . $vendor;
        $token = bin2hex(random_bytes(16));
        $now = $this->clock->now()->getTimestamp();
        $video = $method === 'POST' && str_contains((string) parse_url($url, PHP_URL_PATH), '/models/bytedance/');
        $script = <<<'LUA'
local base, now, token = KEYS[1], tonumber(ARGV[1]), ARGV[2]
if redis.call('EXISTS', base..':open') == 1 then return 0 end
redis.call('ZREMRANGEBYSCORE', base..':leases', '-inf', now)
if redis.call('ZCARD', base..':leases') >= tonumber(ARGV[3]) then return 0 end
local minute = base..':minute:'..math.floor(now/60)
if tonumber(redis.call('GET', minute) or '0') >= tonumber(ARGV[4]) then return 0 end
local hour = base..':video:'..math.floor(now/3600)
if ARGV[5] == '1' and tonumber(redis.call('GET', hour) or '0') >= tonumber(ARGV[6]) then return 0 end
redis.call('INCR', minute); redis.call('EXPIRE', minute, 120)
if ARGV[5] == '1' then redis.call('INCR', hour); redis.call('EXPIRE', hour, 7200) end
redis.call('ZADD', base..':leases', now+120, token); redis.call('EXPIRE', base..':leases', 180)
return 1
LUA;
        try {
            $allowed = $this->redis->eval($script, [$key, $now, $token, $this->config->int('content_operations.concurrency', 2), $this->config->int('content_operations.provider_per_minute', 20), $video ? '1' : '0', $this->config->int('content_operations.video_per_hour', 3)], 1);
        } catch (\Throwable) {
            throw new ProviderException('limits_unavailable', true);
        }
        if ($allowed !== 1) {
            throw new ProviderException('provider_limited', true);
        }
        return ['key' => $key, 'token' => $token];
    }

    /** Retries consume the same provider request budget; a 429 cannot multiply traffic beyond the cap. */
    public function retry(string $url): void
    {
        $vendor = match ((string) parse_url($url, PHP_URL_HOST)) {
            'api.openai.com' => 'openai', 'api.replicate.com' => 'replicate', 'api.tineye.com' => 'tineye', default => throw new ProviderException('invalid_provider_host'),
        };
        $key = 'content-provider:' . $vendor . ':minute:' . intdiv($this->clock->now()->getTimestamp(), 60);
        try {
            $n = $this->redis->eval("local n=redis.call('INCR',KEYS[1]); redis.call('EXPIRE',KEYS[1],120); return n", [$key], 1);
        } catch (\Throwable) {
            throw new ProviderException('limits_unavailable', true);
        }
        if ((int) $n > $this->config->int('content_operations.provider_per_minute', 20)) {
            throw new ProviderException('provider_limited', true);
        }
    }

    /** @param array{key:string,token:string} $lease */
    public function release(array $lease, bool $failed): void
    {
        try {
            $this->redis->zRem($lease['key'] . ':leases', $lease['token']);
            if ($failed) {
                $this->redis->incr($lease['key'] . ':errors');
                $n = $this->redis->incr($lease['key'] . ':failures');
                $this->redis->expire($lease['key'] . ':failures', 300);
                if ($n >= 3) {
                    $this->redis->setex($lease['key'] . ':open', 60, '1');
                }
            } else {
                $this->redis->del($lease['key'] . ':failures');
            }
        } catch (\Throwable) {
            // The lease expires even if release cannot reach Redis. No provider diagnostic is logged.
        }
    }
}
