<?php

declare(strict_types=1);

namespace App\Domain\Source;

/** Supported content origins, independent of the future reader transport. */
enum SourceType: string
{
    case Telegram = 'telegram';

    public function label(): string
    {
        return 'Telegram';
    }
}
