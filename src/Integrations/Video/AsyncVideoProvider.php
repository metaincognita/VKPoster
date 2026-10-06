<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** Optional async extension; Fake and synchronous providers retain their existing interface. */
interface AsyncVideoProvider extends VideoProvider
{
    /** Starts once; opaque provider ID must be committed before polling. */
    public function start(VideoInput $input): string;
    /** Null means still running. Polling never submits another paid generation. */
    public function poll(string $providerJobId, string $localJobId): ?VideoResult;
}
