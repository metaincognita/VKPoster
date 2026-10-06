<?php

declare(strict_types=1);

namespace App\Kernel\Console\Command;

use App\Domain\Content\Operations\ContentMaintenance;
use App\Kernel\Console\Command;
use App\Kernel\Console\Output;

/** Bounded content recovery plus dry-run orphan retention; --apply still requires the retention env opt-in. */
final class ContentMaintainCommand implements Command
{
    public function __construct(private readonly ContentMaintenance $maintenance)
    {
    }
    public function name(): string
    {
        return 'content:maintain';
    }
    public function description(): string
    {
        return 'Recover interrupted processing; preview retention (use --apply with CONTENT_RETENTION_ENABLED)';
    }
    public function run(array $args, Output $out): int
    {
        $out->line(json_encode(['recovery' => $this->maintenance->recover(), 'retention' => $this->maintenance->prune(in_array('--apply', $args, true))], JSON_THROW_ON_ERROR));
        return 0;
    }
}
