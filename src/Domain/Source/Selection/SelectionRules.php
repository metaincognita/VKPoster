<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

use App\Domain\Source\SourceException;

/** Validated deterministic rules. OR inside a list, AND between categories; excludes win. */
final readonly class SelectionRules
{
    /** @param array<string, list<string>|string> $values */
    private function __construct(public array $values)
    {
    }

    /** @param array<string, mixed> $input @throws SourceException */
    public static function fromInput(array $input): self
    {
        $values = [];
        foreach (['include_keywords', 'exclude_keywords', 'include_hashtags', 'exclude_hashtags'] as $key) {
            $raw = $input[$key] ?? '';
            if (!is_string($raw) || mb_strlen($raw) > 10000) {
                throw new SourceException('Введите не более 100 строк, до 100 символов в каждой.', $key);
            }
            $split = preg_split('/\R/u', $raw);
            $lines = $split === false ? [] : $split;
            $list = [];
            foreach ($lines as $line) {
                $line = mb_strtolower(trim($line));
                if (str_contains($key, 'hashtags')) {
                    $line = ltrim($line, '#');
                }
                if ($line === '') {
                    continue;
                }
                if (mb_strlen($line) > 100 || (str_contains($key, 'hashtags') && preg_match('/^[\p{L}\p{N}_]+$/u', $line) !== 1)) {
                    throw new SourceException('Проверьте строки: до 100 символов; хэштег — без пробелов и знаков, кроме # и _.', $key);
                }
                $list[] = $line;
            }
            $list = array_values(array_unique($list));
            if (count($list) > 100) {
                throw new SourceException('Оставьте не более 100 строк.', $key);
            }
            $values[$key] = $list;
        }
        $types = $input['content_types'] ?? [];
        if (!is_array($types) || !array_is_list($types)) {
            throw new SourceException('Выберите типы: текст, фото или альбом.', 'content_types');
        }
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, ['text', 'photo', 'album'], true)) {
                throw new SourceException('Выберите типы: текст, фото или альбом.', 'content_types');
            }
        }
        $values['content_types'] = array_values(array_unique($types));
        foreach (['links', 'forwarded'] as $key) {
            $value = $input[$key] ?? 'any';
            if (!is_string($value) || !in_array($value, ['any', 'yes', 'no'], true)) {
                throw new SourceException('Выберите условие из списка.', $key);
            }
            $values[$key] = $value;
        }
        return new self($values);
    }

    /** @param array<string, list<string>|string> $snapshot */
    public static function fromSnapshot(array $snapshot): self
    {
        $input = $snapshot;
        foreach (['include_keywords', 'exclude_keywords', 'include_hashtags', 'exclude_hashtags'] as $key) {
            $input[$key] = implode("\n", is_array($snapshot[$key]) ? $snapshot[$key] : []);
        }
        return self::fromInput($input);
    }

    /** @return array<string, list<string>|string> Form representation with one value per line. */
    public function form(): array
    {
        $values = $this->values;
        foreach (['include_keywords', 'exclude_keywords', 'include_hashtags', 'exclude_hashtags'] as $key) {
            $values[$key] = implode("\n", is_array($values[$key]) ? $values[$key] : []);
        }
        return $values;
    }
}
