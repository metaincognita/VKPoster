<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** Provider-neutral immutable request; image bytes remain in private storage, never in the browser or logs. */
final readonly class VideoInput
{
    /** @param array<string,mixed>|null $image Private archived image descriptor, not a user-controlled URL. */
    public function __construct(public string $jobId, public string $aspectRatio, public int $duration, public string $instruction, public string $text, public ?array $image)
    {
    }
}
