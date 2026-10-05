<?php

declare(strict_types=1);

namespace App\Domain\Source;

/** System connection state, never taken from user input or inferred from enabled. */
enum SourceStatus: string
{
    case NotConnected = 'not_connected';
    case Connected = 'connected';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NotConnected => 'Не подключён',
            self::Connected => 'Подключён',
            self::Error => 'Ошибка подключения',
        };
    }
}
