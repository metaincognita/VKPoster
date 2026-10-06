<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

/** Replaceable semantic adapter; implementations must treat material as data and never log payloads or credentials. */
interface SemanticSelectionProvider
{
    public function name(): string;
    public function evaluate(SemanticSelectionInput $input): SemanticSelectionResult;
}
