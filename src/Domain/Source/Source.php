<?php

declare(strict_types=1);

namespace App\Domain\Source;

use DateTimeImmutable;

/**
 * Content origin owned by a workspace. It has no publishing credentials or destination channels.
 * Enabled is configuration only; no reader is connected in the initial skeleton.
 */
final class Source
{
    public function __construct(
        public readonly int $id,
        public readonly string $publicId,
        public readonly int $workspaceId,
        public readonly string $name,
        public readonly SourceType $type,
        public readonly string $telegramUsername,
        public readonly SourceStatus $status,
        public readonly bool $enabled,
        public readonly DateTimeImmutable $createdAt,
        public readonly DateTimeImmutable $updatedAt,
        public readonly int $connectionVersion = 1,
    ) {
    }

    public function telegramUrl(): string
    {
        return 'https://t.me/' . $this->telegramUsername;
    }
}
