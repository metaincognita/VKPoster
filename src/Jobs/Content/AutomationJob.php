<?php

declare(strict_types=1);

namespace App\Jobs\Content;

use App\Domain\Content\Automation\Automation;
use App\Kernel\Queue\AbstractJob;

/** Existing Queue job carrying only a durable automation run ID; no content or credentials in payload. */
final class AutomationJob extends AbstractJob
{
    public function __construct(public readonly int $runId)
    {
    }
    public static function fromPayload(array $payload): static
    {
        return new self((int) ($payload['run_id'] ?? 0));
    }
    public function toPayload(): array
    {
        return ['run_id' => $this->runId];
    }
    public function handle(Automation $automation): void
    {
        $automation->run($this->runId);
    }
}
