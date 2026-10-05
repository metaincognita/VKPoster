<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Domain\Content\Processing\TextSettings;
use RuntimeException;

/** Offline demonstration only; does not translate or semantically execute custom instructions. Disabled in production. */
final class FakeTextProvider implements TextProvider
{
    public function __construct(private readonly bool $allowed = true)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function generate(string $text, TextSettings $settings): string
    {
        if (!$this->allowed) {
            throw new RuntimeException('Text provider unavailable');
        }
        return match ($settings->mode) {
            'edit' => trim(preg_replace('/[ \t]+/u', ' ', $text) ?? $text),
            'rewrite' => 'Тестовая редакция: ' . trim($text),
            'shorten' => mb_substr(trim($text), 0, $settings->maxLength),
            'custom' => 'Тестовая обработка: ' . trim($text),
            default => $text,
        };
    }
}
