<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source;

use App\Domain\Source\Selection\SelectionEngine;
use App\Domain\Source\Selection\SelectionRules;
use App\Domain\Source\SourceException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SelectionEngineTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>|null, string, string, list<array<string, mixed>>, string, string}> */
    public static function scenarios(): iterable
    {
        yield 'unconfigured' => [null, '', 'text', [], 'needs_review', 'rules.not_configured'];
        yield 'empty unrestricted' => [[], '', 'text', [], 'approved', 'rules.matched'];
        yield 'unicode keywords OR' => [['include_keywords' => "наука\nКОСМОС"], 'Новости космоса', 'text', [], 'approved', 'rules.matched'];
        yield 'exclude wins' => [['include_keywords' => 'наука', 'exclude_keywords' => 'реклама'], 'НАУКА РЕКЛАМА', 'text', [], 'rejected', 'exclude_keywords'];
        yield 'missing include' => [['include_keywords' => 'наука'], 'Спорт', 'text', [], 'rejected', 'include_keywords'];
        yield 'hashtag exact' => [['include_hashtags' => '#наука'], '#НАУКА_дня', 'text', [], 'rejected', 'include_hashtags'];
        yield 'hashtags unicode' => [['include_hashtags' => "#наука\n#новости"], '#НОВОСТИ', 'text', [], 'approved', 'rules.matched'];
        yield 'exclude hashtag before include' => [['include_keywords' => 'missing', 'exclude_hashtags' => '#реклама'], '#реклама', 'text', [], 'rejected', 'exclude_hashtags'];
        yield 'AND categories' => [['include_keywords' => 'наука', 'include_hashtags' => '#наука'], 'наука без тега', 'text', [], 'rejected', 'include_hashtags'];
        yield 'photo accepted' => [['content_types' => ['photo']], '', 'photo', [], 'approved', 'rules.matched'];
        yield 'type mismatch' => [['content_types' => ['text']], '', 'album', [], 'rejected', 'content_types'];
        yield 'album caption union' => [['content_types' => ['album'], 'include_keywords' => 'second'], "first\nsecond", 'album', [], 'approved', 'rules.matched'];
        yield 'plain url' => [['links' => 'yes'], 'HTTPS://example.org', 'text', [], 'approved', 'rules.matched'];
        yield 'www url' => [['links' => 'no'], 'www.example.org', 'text', [], 'rejected', 'links'];
        yield 'hidden Telegram link' => [['links' => 'yes'], 'Открыть', 'text', [['entities' => [['_' => 'MessageEntityTextUrl', 'url' => 'https://example.org']]]], 'approved', 'rules.matched'];
        yield 'Telegram bare url' => [['links' => 'yes'], 'example.org', 'text', [['entities' => [['_' => 'MessageEntityUrl']]]], 'approved', 'rules.matched'];
        yield 'no links' => [['links' => 'yes'], '', 'text', [], 'rejected', 'links'];
        yield 'explicit unforwarded' => [['forwarded' => 'no'], '', 'text', [['forward' => null, 'forward_known' => true]], 'approved', 'rules.matched'];
        yield 'unknown forward' => [['forwarded' => 'no'], '', 'text', [['forward' => null]], 'needs_review', 'forwarded.unknown'];
        yield 'forwarded album member' => [['forwarded' => 'yes'], '', 'album', [[], ['forward' => ['channel_id' => 123]]], 'approved', 'rules.matched'];
        yield 'reject forwarded' => [['forwarded' => 'no'], '', 'photo', [['forward' => ['channel_id' => 123]]], 'rejected', 'forwarded'];
        yield 'reject not forwarded' => [['forwarded' => 'yes'], '', 'text', [['forward_known' => true]], 'rejected', 'forwarded'];
        yield 'deterministic reject before missing' => [['exclude_keywords' => 'ad', 'forwarded' => 'yes'], 'ad', 'text', [], 'rejected', 'exclude_keywords'];
    }

    /**
     * @param array<string, mixed>|null $input
     * @param list<array<string, mixed>> $messages
     */
    #[DataProvider('scenarios')]
    public function testDecisions(?array $input, string $text, string $type, array $messages, string $status, string $code): void
    {
        $result = (new SelectionEngine())->evaluate($input === null ? null : SelectionRules::fromInput($input), $text, $type, $messages);
        self::assertSame($status, $result->status);
        self::assertSame($code, $result->rule);
        self::assertNotSame('', $result->reason);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidInputs(): iterable
    {
        yield 'array text' => [['include_keywords' => []]];
        yield 'too long' => [['exclude_keywords' => str_repeat('a', 101)]];
        yield 'too many' => [['include_keywords' => implode("\n", range(1, 101))]];
        yield 'max form length' => [['include_keywords' => str_repeat('a', 10001)]];
        yield 'invalid hashtag' => [['include_hashtags' => '#two words']];
        yield 'bad links' => [['links' => 'maybe']];
        yield 'array forwarded' => [['forwarded' => []]];
        yield 'bad type' => [['content_types' => ['video']]];
        yield 'not list' => [['content_types' => ['key' => 'text']]];
        yield 'type scalar' => [['content_types' => 'photo']];
        yield 'type nested' => [['content_types' => [[]]]];
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidInputs')]
    public function testValidation(array $input): void
    {
        $this->expectException(SourceException::class);
        SelectionRules::fromInput($input);
    }

    public function testNormalizationAndSnapshotRoundtrip(): void
    {
        $rules = SelectionRules::fromInput(['include_keywords' => " НАУКА \r\nнаука\n", 'include_hashtags' => "#КОСМОС\nкосмос", 'content_types' => ['photo','photo']]);
        self::assertSame(['наука'], $rules->values['include_keywords']);
        self::assertSame(['космос'], $rules->values['include_hashtags']);
        self::assertSame(['photo'], $rules->values['content_types']);
        self::assertSame($rules->values, SelectionRules::fromSnapshot($rules->values)->values);
        self::assertSame('наука', $rules->form()['include_keywords']);
    }
}
