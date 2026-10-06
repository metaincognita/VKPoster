<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

/** Safe provider failure category, with no upstream body or nested transport exception. */
final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $category, public readonly bool $retryable = false)
    {
        parent::__construct('Content provider: ' . $category);
    }
}
