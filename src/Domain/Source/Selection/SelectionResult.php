<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

/** Selection is independent of ingestion/processing state; a reason never contains post contents. */
final readonly class SelectionResult
{
    public function __construct(public string $status, public string $reason, public string $rule)
    {
    }
}
