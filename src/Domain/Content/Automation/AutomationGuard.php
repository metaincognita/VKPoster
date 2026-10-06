<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Kernel\Config;

/** Cost guard: explicit operation permission, real credentials and no demonstration work in production. */
final class AutomationGuard
{
    public function __construct(private readonly Config $config)
    {
    }
    public function allows(string $operation): bool
    {
        $provider = $this->config->string('content_providers.' . $operation);
        if ($provider === 'fake') {
            return !$this->config->isProduction();
        }
        if ($provider === 'disabled') {
            return true;
        }
        $key = match ($provider) {
            'openai' => 'openai_key', 'tineye' => 'tineye_key', 'replicate' => 'replicate_token', default => null,
        };
        return $key !== null && trim($this->config->string('content_providers.' . $key)) !== '';
    }
    public function discovery(): bool
    {
        // All current Discovery providers are fixtures; no background fixtures in production.
        return !$this->config->isProduction();
    }
}
