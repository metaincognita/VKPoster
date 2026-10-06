<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

use App\Integrations\Ai\OpenAiResponses;
use App\Integrations\ContentProviders\ProviderMetadata;
use App\Integrations\ContentProviders\ProviderException;

/** Strict structured semantic adapter; scores and confidence remain independent of deterministic/manual policy. */
final class OpenAiSemanticSelectionProvider implements SemanticSelectionProvider, ProviderMetadata
{
    public function __construct(private readonly OpenAiResponses $api)
    {
    }
    public function name(): string
    {
        return 'openai';
    }
    public function evaluate(SemanticSelectionInput $input): SemanticSelectionResult
    {
        $properties = ['decision' => ['type' => 'string', 'enum' => ['approved', 'rejected', 'needs_review']], 'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100], 'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1], 'reason' => ['type' => 'string', 'maxLength' => 512], 'matched_criteria' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 128]], 'review_flags' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'maxLength' => 128]]];
        $row = $this->api->structured(SemanticSelectionInput::POLICY . ' Explain briefly in Russian. Trusted criteria: ' . json_encode($input->criteria, JSON_THROW_ON_ERROR), ['text' => $input->text, 'title' => $input->title, 'metadata' => $input->metadata, 'revision' => $input->revision], ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false]);
        if (count($row) !== count($properties) || !is_string($row['decision'] ?? null) || !is_int($row['score'] ?? null) || (!is_float($row['confidence'] ?? null) && !is_int($row['confidence'] ?? null)) || !is_string($row['reason'] ?? null) || !is_array($row['matched_criteria'] ?? null) || !is_array($row['review_flags'] ?? null)) {
            throw new ProviderException('invalid_semantic_output');
        }
        foreach ([$row['matched_criteria'], $row['review_flags']] as $values) {
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new ProviderException('invalid_semantic_output');
                }
            }
        }
        return new SemanticSelectionResult($row['decision'], $row['score'], (float) $row['confidence'], $row['reason'], $row['matched_criteria'], $row['review_flags']);
    }
    public function metadata(): array
    {
        return $this->api->metadata();
    }
}
