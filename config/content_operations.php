<?php

declare(strict_types=1);

use App\Kernel\Env;

return static fn (Env $env): array => [
    'concurrency' => max(1, min(16, $env->int('CONTENT_CONCURRENCY', 2))),
    'provider_per_minute' => max(1, min(600, $env->int('CONTENT_PROVIDER_PER_MINUTE', 20))),
    'video_per_hour' => max(1, min(100, $env->int('CONTENT_VIDEO_PER_HOUR', 3))),
    'video_pending' => max(1, min(100, $env->int('CONTENT_VIDEO_PENDING', 3))),
    'retention_enabled' => $env->bool('CONTENT_RETENTION_ENABLED', false),
    'retention_days' => max(7, min(3650, $env->int('CONTENT_RETENTION_DAYS', 30))),
    'stuck_seconds' => max(1800, $env->int('CONTENT_STUCK_SECONDS', 3600)),
];
