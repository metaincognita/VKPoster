<?php

declare(strict_types=1);

namespace App\Integrations\ContentProviders;

/** Optional non-breaking boundary for allowlisted usage/model metadata; never prompts, credentials or signed URLs. */
interface ProviderMetadata
{
    /** @return array<string,mixed> */
    public function metadata(): array;
}
