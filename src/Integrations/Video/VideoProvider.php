<?php

declare(strict_types=1);

namespace App\Integrations\Video;

/** Replaceable generation boundary, independent of model names and publishing jobs. */
interface VideoProvider
{
    /** Provider calls occur outside database locks; exceptions are replaced with safe messages. */
    public function generate(VideoInput $input): VideoResult;
    public function name(): string;
}
