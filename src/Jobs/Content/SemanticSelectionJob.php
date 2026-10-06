<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Domain\Source\Selection\SelectionService;
use App\Domain\Source\Selection\SemanticSelection;
use App\Kernel\Queue\AbstractJob;

/** Queued semantic attempt: payload contains only the durable attempt ID. */
final class SemanticSelectionJob extends AbstractJob
{
    public function __construct(public readonly int $attemptId)
    {
    }
    public static function fromPayload(array $payload): static
    {
        return new self((int) ($payload['attempt_id'] ?? 0));
    }
    public function toPayload(): array
    {
        return ['attempt_id' => $this->attemptId];
    }
    public function handle(SemanticSelection $semantic, SelectionService $selection): void
    {
        try {
            $semantic->run($this->attemptId, $selection);
        } catch (\App\Integrations\ContentProviders\ProviderException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new \RuntimeException('Semantic attempt was not committed; inspect durable state.');
        }
    }
}
