<?php

declare(strict_types=1);

namespace App\Domain\Source;

use RuntimeException;

/** A source validation failure with a safe Russian message and its form field. */
final class SourceException extends RuntimeException
{
    public function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }
}
