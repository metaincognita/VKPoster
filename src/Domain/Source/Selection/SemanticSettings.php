<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

use App\Domain\Source\SourceException;

/** Versioned semantic policy; disabled by default, never substitutes for deterministic rules. */
final readonly class SemanticSettings
{
    public function __construct(public bool $enabled, public string $criteria, public int $minScore, public float $minConfidence, public string $uncertainMode)
    {
        if (mb_strlen($criteria) > 4000 || !mb_check_encoding($criteria, 'UTF-8') || ($enabled && trim($criteria) === '')) {
            throw new SourceException('Укажите критерии, не более 4000 символов.', 'criteria');
        }
        if ($minScore < 0 || $minScore > 100) {
            throw new SourceException('Score должен быть от 0 до 100.', 'min_score');
        }
        if (!is_finite($minConfidence) || $minConfidence < 0 || $minConfidence > 1) {
            throw new SourceException('Уверенность должна быть от 0 до 1.', 'min_confidence');
        }
        if (!in_array($uncertainMode, ['review', 'reject'], true)) {
            throw new SourceException('Выберите режим сомнительных материалов.', 'uncertain_mode');
        }
    }
    /** @param array<string,mixed> $input */
    public static function fromInput(array $input): self
    {
        if (!in_array($input['enabled'] ?? false, [true, false, 1, 0, '1', '0'], true)) {
            throw new SourceException('Выберите, включён ли смысловой отбор.', 'enabled');
        }
        $score = $input['min_score'] ?? 70;
        $confidence = $input['min_confidence'] ?? 0.8;
        if (!is_int($score) && (!is_string($score) || preg_match('/^\d{1,3}$/D', $score) !== 1)) {
            throw new SourceException('Score — целое число 0–100.', 'min_score');
        }
        if (!is_int($confidence) && !is_float($confidence) && (!is_string($confidence) || preg_match('/^(?:0(?:\.\d{1,4})?|1(?:\.0{1,4})?)$/D', $confidence) !== 1)) {
            throw new SourceException('Уверенность — число 0–1.', 'min_confidence');
        }
        if (!is_string($input['criteria'] ?? '') || !is_string($input['uncertain_mode'] ?? 'review')) {
            throw new SourceException('Проверьте критерии отбора.', 'criteria');
        }
        return new self(in_array($input['enabled'] ?? false, [true, 1, '1'], true), trim($input['criteria'] ?? ''), (int) $score, (float) $confidence, $input['uncertain_mode'] ?? 'review');
    }
    /** @return array<string,bool|string|int|float> */
    public function snapshot(): array
    {
        return ['enabled' => $this->enabled, 'criteria' => $this->criteria, 'min_score' => $this->minScore, 'min_confidence' => $this->minConfidence, 'uncertain_mode' => $this->uncertainMode];
    }
}
