<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Content\Operations\ContentStatus;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/** Safe automation/reader/provider/queue metrics for operations without adding a public diagnostic endpoint. */
final class ContentStatusCommand implements Command
{
    public function __construct(private readonly ContentStatus $status)
    {
    }
    public function name(): string
    {
        return 'content:status';
    }
    public function description(): string
    {
        return 'Read content automation, provider, reader and queue health';
    }
    public function run(array $args, Output $out): int
    {
        $out->line(json_encode($this->status->report(), JSON_THROW_ON_ERROR));
        return 0;
    }
}
