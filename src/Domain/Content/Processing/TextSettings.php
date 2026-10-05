<?php

declare(strict_types=1);

namespace App\Domain\Content\Processing;

use App\Domain\Source\SourceException;

/** Validated, immutable text processing settings; every run persists its own snapshot. */
final readonly class TextSettings
{
    public const MODES = ['unchanged', 'edit', 'rewrite', 'shorten', 'custom'];
    public const LANGUAGES = ['original', 'ru', 'en', 'uk', 'de', 'fr', 'es'];

    /** @param list<string> $forbidden */
    private function __construct(public string $mode, public string $language, public int $maxLength, public bool $keepLinks, public bool $keepSource, public string $instruction, public array $forbidden)
    {
    }

    /** @param array<string, mixed> $input */
    public static function fromInput(array $input): self
    {
        $values = [];
        foreach (['mode' => 'unchanged', 'language' => 'original', 'max_length' => '4096', 'links' => 'keep', 'source_mentions' => 'keep', 'instruction' => '', 'forbidden' => ''] as $key => $default) {
            $value = $input[$key] ?? $default;
            if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
                throw new SourceException('Проверьте значение поля.', $key);
            }
            $values[$key] = trim($value);
        }
        foreach (['mode' => self::MODES, 'language' => self::LANGUAGES, 'links' => ['keep', 'remove'], 'source_mentions' => ['keep', 'remove']] as $key => $allowed) {
            if (!in_array($values[$key], $allowed, true)) {
                throw new SourceException('Выберите значение из списка.', $key);
            }
        }
        if (!ctype_digit($values['max_length']) || (int) $values['max_length'] < 1 || (int) $values['max_length'] > 20000) {
            throw new SourceException('Укажите длину от 1 до 20000 символов.', 'max_length');
        }
        if (mb_strlen($values['instruction']) > 4000 || ($values['mode'] === 'custom' && $values['instruction'] === '')) {
            throw new SourceException('Укажите инструкцию до 4000 символов.', 'instruction');
        }
        if (mb_strlen($values['forbidden']) > 10000) {
            throw new SourceException('Список слишком длинный.', 'forbidden');
        }
        $forbidden = array_values(array_unique(array_filter(array_map('trim', explode("\n", $values['forbidden'])), static fn (string $v): bool => $v !== '')));
        if (count($forbidden) > 100) {
            throw new SourceException('Укажите до 100 слов или фраз.', 'forbidden');
        }
        foreach ($forbidden as $phrase) {
            if (mb_strlen($phrase) > 100) {
                throw new SourceException('Каждая фраза — до 100 символов.', 'forbidden');
            }
        }
        // Longest phrases first prevents a shorter exclusion leaving part of a longer phrase.
        usort($forbidden, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        return new self($values['mode'], $values['language'], (int) $values['max_length'], $values['links'] === 'keep', $values['source_mentions'] === 'keep', $values['instruction'], $forbidden);
    }

    /** @return array<string, string> */
    public function form(): array
    {
        return ['mode' => $this->mode, 'language' => $this->language, 'max_length' => (string) $this->maxLength, 'links' => $this->keepLinks ? 'keep' : 'remove', 'source_mentions' => $this->keepSource ? 'keep' : 'remove', 'instruction' => $this->instruction, 'forbidden' => implode("\n", $this->forbidden)];
    }
}
