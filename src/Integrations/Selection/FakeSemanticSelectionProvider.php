<?php

declare(strict_types=1);

namespace App\Integrations\Selection;

/** Local fixture demonstrator, not AI or natural-language understanding; unavailable in production. */
final class FakeSemanticSelectionProvider implements SemanticSelectionProvider
{
    public function __construct(private readonly bool $allowed = true)
    {
    }
    public function name(): string
    {
        return 'fake';
    }
    public function evaluate(SemanticSelectionInput $input): SemanticSelectionResult
    {
        if (!$this->allowed) {
            throw new \RuntimeException('Fake semantic provider unavailable');
        }
        $text = mb_strtolower(($input->title ?? '') . ' ' . $input->text);
        // No execution or instruction interpolation. Suspicious content is a review flag, never a policy override.
        if (preg_match('/ignore.{0,30}(instructions|criteria)|system\s*prompt|игнорир.{0,30}(инструкц|критери)|<\/?system>|decision\s*[:=]/iu', $text) === 1) {
            return new SemanticSelectionResult('needs_review', 0, 0.2, 'Демонстрация: обнаружены инструкции внутри материала.', [], ['untrusted_instructions']);
        }
        /** @var list<array{terms:list<string>,decision:string,score:int,confidence:float,reason:string}> $fixtures */
        $fixtures = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/selection/semantic-fake.json'), true, 32, JSON_THROW_ON_ERROR);
        foreach ($fixtures as $fixture) {
            foreach ($fixture['terms'] as $term) {
                // Trusted user criteria must include the fixture's category; arbitrary criteria are not pretended understood.
                if (str_contains($text, $term) && str_contains(mb_strtolower($input->criteria), $term)) {
                    return new SemanticSelectionResult($fixture['decision'], $fixture['score'], $fixture['confidence'], $fixture['reason'], ['Категория демонстрационной fixture'], $fixture['decision'] === 'needs_review' ? ['ambiguous_fixture'] : []);
                }
            }
        }
        return new SemanticSelectionResult('needs_review', 50, 0.3, 'Fake provider не интерпретирует произвольные критерии. Нужна ручная проверка.', [], ['fake_unknown']);
    }
}
