<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Domain\Audit\AuditLog;
use App\Domain\Workspace\WorkspaceContext;
use App\Kernel\Database\Connection;
use LogicException;
use PDOException;

/**
 * Validates and saves source configuration with an atomic audit entry. Permissions are checked
 * by the routes. It never connects readers, creates posts or enqueues jobs.
 */
final class SourceService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SourceRepository $sources,
        private readonly TelegramSourceLocator $locator,
        private readonly AuditLog $audit,
    ) {
    }

    /** @throws SourceException for invalid input or a duplicate workspace source. */
    public function create(WorkspaceContext $context, string $name, string $type, string $reference, bool $enabled = false): Source
    {
        return $this->save($context, null, $name, $type, $reference, $enabled);
    }

    /**
     * @throws SourceException for invalid input or a duplicate workspace source.
     * @throws LogicException when the source does not belong to this workspace.
     */
    public function update(WorkspaceContext $context, Source $source, string $name, string $type, string $reference, bool $enabled): Source
    {
        return $this->save($context, $source, $name, $type, $reference, $enabled);
    }

    private function save(WorkspaceContext $context, ?Source $source, string $name, string $type, string $reference, bool $enabled): Source
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 255) {
            throw new SourceException('Введите название длиной от 1 до 255 символов.', 'name');
        }
        $sourceType = SourceType::tryFrom($type) ?? throw new SourceException('Выберите тип источника Telegram.', 'type');
        $username = $this->locator->normalize($reference);
        try {
            return $this->db->transaction(function () use ($context, $source, $name, $sourceType, $username, $enabled): Source {
                $saved = $source === null
                    ? $this->sources->create($context, $name, $sourceType, $username, $enabled)
                    : $this->sources->update($context, $source, $name, $sourceType, $username, $enabled);
                $this->audit->record($source === null ? 'source.created' : 'source.updated', $context->userId, 'source', $saved->publicId, ['name' => $saved->name], $context->workspaceId);

                return $saved;
            });
        } catch (PDOException $e) {
            // The unique index is authoritative, including simultaneous create/update requests.
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new SourceException('Этот Telegram-канал уже добавлен в источники пространства.', 'reference');
            }
            throw $e;
        }
    }
}
