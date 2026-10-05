<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\Source\Source;
use App\Domain\Source\SourceIngress;

/** Synthetic reader snapshots for selection tests. Never touches Telegram or publishing. */
abstract class SourceSelectionTestCase extends WorkspaceTestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    protected function message(int $id, string $text = 'Наука #космос', ?string $group = null, array $extra = []): array
    {
        return array_replace(['channel_id' => '12345', 'message_id' => $id, 'grouped_id' => $group, 'text' => $text,
            'entities' => [], 'media' => $group === null ? null : ['kind' => 'photo'], 'forward' => null,
            'date' => '2026-10-05T10:00:00Z', 'edit_date' => null, 'content_hash' => hash('sha256', $text . $id)], $extra);
    }

    /** @param list<array<string, mixed>> $messages */
    protected function ingest(Source $source, array $messages): bool
    {
        $payload = ['peer_id' => '12345', 'grouped_id' => $messages[0]['grouped_id'], 'messages' => $messages];
        return $this->app->container()->get(SourceIngress::class)->accept(['version' => 1, 'source_id' => $source->publicId, 'kind' => 'item', 'payload' => $payload, 'event_id' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR))]);
    }

    /**
     * @return array<string, mixed>
     * @phpstan-impure
     */
    protected function decision(): array
    {
        return $this->db->table('source_selection_decisions')->first() ?? throw new \LogicException('Missing decision');
    }
}
