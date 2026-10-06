<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** Local lifecycle demonstration only: no network, files, rendering or actual video. */
final class FakeVideoProvider implements VideoProvider
{
    public function __construct(private readonly bool $allowed = true)
    {
    }
    public function generate(VideoInput $input): VideoResult
    {
        if (!$this->allowed) {
            throw new \RuntimeException('Video provider unavailable');
        }
        return new VideoResult(true);
    }
    public function name(): string
    {
        return 'fake';
    }
}
