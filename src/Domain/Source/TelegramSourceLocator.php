<?php

declare(strict_types=1);

namespace App\Domain\Source;

/**
 * Normalizes public Telegram channel references locally. No network resolution is performed;
 * a username is a locator, not a stable Telegram peer identity.
 */
final class TelegramSourceLocator
{
    /** @throws SourceException for invite, message or arbitrary external URLs. */
    public function normalize(string $reference): string
    {
        $reference = trim($reference);
        if (preg_match('~\A(?:https://)?t\.me/([A-Za-z][A-Za-z0-9_]{4,31})/?\z~i', $reference, $match) === 1) {
            return strtolower($match[1]);
        }
        if (preg_match('/\A@?([A-Za-z][A-Za-z0-9_]{4,31})\z/', $reference, $match) === 1) {
            return strtolower($match[1]);
        }

        throw new SourceException('Укажите публичный канал: @username или https://t.me/username. Ссылки на отдельные посты и приглашения не подходят.', 'reference');
    }
}
