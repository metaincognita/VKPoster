<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Domain\Content\Processing\TextSettings;

/** Provider boundary: implementations must bound request time/output and never log content or credentials. */
interface TextProvider
{
    public function name(): string;

    /** Input is untrusted content, never system instructions; settings carry the trusted processing instruction. */
    public function generate(string $text, TextSettings $settings): string;
}
