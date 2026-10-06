<?php

declare(strict_types=1);

namespace App\Domain\Source;

use App\Domain\Workspace\WorkspaceContext;
use App\Domain\Workspace\WorkspaceScopedRepository;
use App\Kernel\Database\Connection;
use App\Support\Clock;
use App\Support\DbTime;
use DateTimeImmutable;
use LogicException;
use Symfony\Component\Uid\Ulid;

/** Sources of the resolved workspace; every lookup and update is scoped to its context. */
final class SourceRepository extends WorkspaceScopedRepository
{
    public function __construct(Connection $db, private readonly Clock $clock)
    {
        parent::__construct($db);
    }

    /** @return list<Source> newest first */
    public function all(WorkspaceContext $context): array
    {
        return array_map(self::hydrate(...), $this->scoped($context, 'sources')->orderBy('id', 'desc')->get());
    }

    public function find(WorkspaceContext $context, string $publicId): ?Source
    {
        $row = $this->scoped($context, 'sources')->where('public_id', '=', strtoupper($publicId))->first();

        return $row === null ? null : self::hydrate($row);
    }

    public function create(WorkspaceContext $context, string $name, SourceType $type, string $username, bool $enabled): Source
    {
        $publicId = (string) new Ulid();
        $now = DbTime::format($this->clock->now());
        $this->db->table('sources')->insert([
            'public_id' => $publicId,
            'workspace_id' => $context->workspaceId,
            'name' => $name,
            'type' => $type->value,
            'telegram_username' => $username,
            'connection_version' => 1,
            'status' => SourceStatus::NotConnected->value,
            'enabled' => $enabled,
            'created_by' => $context->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->find($context, $publicId) ?? throw new LogicException('The inserted source disappeared.');
    }

    /** Updates configuration only; connection status and creator are preserved. */
    public function update(WorkspaceContext $context, Source $source, string $name, SourceType $type, string $username, bool $enabled): Source
    {
        if ($source->workspaceId !== $context->workspaceId) {
            throw new LogicException('Source does not belong to the workspace.');
        }
        return $this->db->transaction(function () use ($context, $source, $name, $type, $username, $enabled): Source {
            $row = $this->db->select('SELECT * FROM sources WHERE workspace_id=? AND id=? FOR UPDATE', [$context->workspaceId, $source->id])[0] ?? throw new LogicException('Source disappeared.');
            $current = self::hydrate($row);
            $this->scoped($context, 'sources')->where('id', '=', $source->id)->update([
                'name' => $name,
                'type' => $type->value,
                'telegram_username' => $username,
                'connection_version' => $current->connectionVersion + ($current->telegramUsername === $username ? 0 : 1),
                'status' => $current->telegramUsername === $username ? $current->status->value : SourceStatus::NotConnected->value,
                'enabled' => $enabled,
                'updated_at' => DbTime::format($this->clock->now()),
            ]);

            return $this->find($context, $source->publicId) ?? throw new LogicException('The updated source disappeared.');
        });
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): Source
    {
        return new Source(
            (int) $row['id'],
            (string) $row['public_id'],
            (int) $row['workspace_id'],
            (string) $row['name'],
            SourceType::from((string) $row['type']),
            (string) $row['telegram_username'],
            SourceStatus::from((string) $row['status']),
            (bool) $row['enabled'],
            DbTime::parse($row['created_at']) ?? new DateTimeImmutable('@0'),
            DbTime::parse($row['updated_at']) ?? new DateTimeImmutable('@0'),
            (int) ($row['connection_version'] ?? 1),
        );
    }
}
