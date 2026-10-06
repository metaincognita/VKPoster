<?php

declare(strict_types=1);

namespace App\Domain\Content\Automation;

use App\Domain\Source\SourceException;

/** Validated policy snapshot. All costly actions require explicit opt-in; publication is never an option. */
final class AutomationSettings
{
    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return ['enabled' => false, 'auto_selection' => false, 'semantic_selection' => false, 'auto_text_processing' => false, 'auto_image_processing' => false, 'auto_video_generation' => false, 'auto_draft' => false, 'manual_review_fallback' => true, 'discovery_enabled' => false, 'interval_minutes' => 60, 'provider_types' => ['telegram', 'web', 'social'], 'candidate_limit' => 10, 'text_mode' => 'unchanged'];
    }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function validate(array $input): array
    {
        $v = self::defaults();
        foreach ($v as $key => $default) {
            if (is_bool($default)) {
                $value = $input[$key] ?? false;
                if (!in_array($value, [false, true, '0', '1'], true)) {
                    throw new SourceException('Проверьте настройку автоматизации.', $key);
                }
                $v[$key] = $value === true || $value === '1';
            }
        }
        foreach (['interval_minutes' => [1, 1440], 'candidate_limit' => [1, 100]] as $key => [$min, $max]) {
            $value = $input[$key] ?? $v[$key];
            if ((!is_int($value) && !is_string($value)) || !ctype_digit((string) $value) || (int) $value < $min || (int) $value > $max) {
                throw new SourceException('Укажите значение в допустимом диапазоне.', $key);
            }
            $v[$key] = (int) $value;
        }
        $types = $input['provider_types'] ?? $v['provider_types'];
        if (!is_array($types) || $types === [] || count($types) > 3) {
            throw new SourceException('Выберите типы обнаружения.', 'provider_types');
        }
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, ['telegram', 'web', 'social'], true)) {
                throw new SourceException('Выберите типы обнаружения.', 'provider_types');
            }
        }
        $v['provider_types'] = array_values(array_unique($types));
        $mode = $input['text_mode'] ?? 'unchanged';
        if (!in_array($mode, ['unchanged', 'edit', 'rewrite', 'shorten'], true)) {
            throw new SourceException('Выберите режим текста.', 'text_mode');
        }
        $v['text_mode'] = $mode;
        return $v;
    }
}
