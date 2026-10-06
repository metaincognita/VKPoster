<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

/** Strict structured provider response. Score measures relevance; confidence measures certainty, never popularity. */
final readonly class SemanticSelectionResult
{
    /**
     * @param array<array-key,string> $matchedCriteria
     * @param array<array-key,string> $reviewFlags */
    public function __construct(public string $decision, public int $score, public float $confidence, public string $reason, public array $matchedCriteria = [], public array $reviewFlags = [])
    {
        if (!in_array($decision, ['approved', 'rejected', 'needs_review'], true) || $score < 0 || $score > 100 || !is_finite($confidence) || $confidence < 0 || $confidence > 1 || $reason === '' || mb_strlen($reason) > 512 || !mb_check_encoding($reason, 'UTF-8')) {
            throw new \InvalidArgumentException('Invalid semantic result');
        }
        foreach ([$matchedCriteria, $reviewFlags] as $values) {
            if (!array_is_list($values) || count($values) > 20) {
                throw new \InvalidArgumentException('Invalid semantic annotations');
            }
            foreach ($values as $value) {
                if ($value === '' || mb_strlen($value) > 128 || !mb_check_encoding($value, 'UTF-8')) {
                    throw new \InvalidArgumentException('Invalid semantic annotation');
                }
            }
        }
    }
}
