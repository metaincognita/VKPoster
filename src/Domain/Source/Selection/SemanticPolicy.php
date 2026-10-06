<?php

declare(strict_types=1);

namespace App\Domain\Source\Selection;

use App\Integrations\Selection\SemanticSelectionResult;

/** Pure composition: deterministic rejection wins over AI; uncertainty cannot silently approve. Manual override is applied separately. */
final class SemanticPolicy
{
    public function combine(SelectionResult $deterministic, SemanticSelectionResult $semantic, SemanticSettings $settings): SelectionResult
    {
        if ($deterministic->status === 'rejected') {
            return $deterministic;
        }
        if ($semantic->reviewFlags !== [] || $semantic->confidence < $settings->minConfidence || $semantic->decision === 'needs_review') {
            return new SelectionResult($settings->uncertainMode === 'reject' && !in_array('untrusted_instructions', $semantic->reviewFlags, true) ? 'rejected' : 'needs_review', 'Смысловой отбор: нужна проверка уверенности или отмеченных рисков.', 'semantic.uncertain');
        }
        if ($semantic->decision === 'rejected') {
            return new SelectionResult('rejected', $semantic->reason, 'semantic.rejected');
        }
        if ($deterministic->status !== 'approved') {
            return $deterministic;
        }
        if ($semantic->score < $settings->minScore) {
            return new SelectionResult($settings->uncertainMode === 'reject' ? 'rejected' : 'needs_review', 'Смысловой score ниже заданного порога.', 'semantic.score');
        }
        return new SelectionResult('approved', $semantic->reason, 'semantic.approved');
    }
}
