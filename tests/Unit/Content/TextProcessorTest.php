<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Domain\Content\Processing\TextProcessor;
use App\Domain\Content\Processing\TextSettings;
use App\Domain\Source\SourceException;
use App\Integrations\Ai\FakeTextProvider;
use App\Integrations\Ai\TextProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TextProcessorTest extends TestCase
{
    public function testUnchangedDoesNotCallProviderAndPreservesWhitespace(): void
    {
        $provider = $this->createMock(TextProvider::class);
        $provider->expects(self::never())->method('generate');
        $processor = new TextProcessor($provider);
        $settings = TextSettings::fromInput([]);
        self::assertSame('local', $processor->providerName($settings));
        self::assertSame("  Оригинал\n🙂 ", $processor->process("  Оригинал\n🙂 ", [], 'sample_channel', $settings));
    }

    public function testConstraintsRemoveOnlySourceMentionAndLinksAndUnicodePhrases(): void
    {
        $p = new TextProcessor(new FakeTextProvider());
        $text = '@SAMPLE_CHANNEL @other_channel https://example.org https://t.me/sample_channel/10 ЗАПРЕТ запрещённая фраза 🙂';
        $settings = TextSettings::fromInput(['source_mentions' => 'remove', 'links' => 'remove', 'forbidden' => "запрет\nзапрещённая фраза", 'max_length' => '200']);
        $result = $p->process($text, [['text' => $text, 'entities_json' => '[]']], 'sample_channel', $settings);
        self::assertStringContainsString('@other_channel', $result);
        self::assertStringContainsString('🙂', $result);
        self::assertStringNotContainsString('https', $result);
        self::assertStringNotContainsString('SAMPLE_CHANNEL', $result);
        self::assertStringNotContainsString('ЗАПРЕТ', $result);
        self::assertStringNotContainsString('запрещённая фраза', $result);
        self::assertSame('abc', $p->process('abc', [], 'sample_channel', TextSettings::fromInput(['source_mentions' => 'keep', 'links' => 'keep'])));
    }

    public function testHiddenSourceLinkUsesUtf16OffsetsAfterEmojiAndAlbumCaptions(): void
    {
        $p = new TextProcessor(new FakeTextProvider());
        $messages = [['text' => '🙂 Автор История', 'entities_json' => json_encode([['_' => 'MessageEntityTextUrl', 'offset' => 3, 'length' => 5, 'url' => 'https://t.me/sample_channel']], JSON_THROW_ON_ERROR)], ['text' => 'Вторая подпись', 'entities_json' => '[]']];
        self::assertSame("🙂  История\nВторая подпись", $p->process("🙂 Автор История\nВторая подпись", $messages, 'sample_channel', TextSettings::fromInput(['source_mentions' => 'remove'])));
        self::assertSame('🙂 Автор (https://t.me/sample_channel) История', $p->process('🙂 Автор История', [$messages[0]], 'sample_channel', TextSettings::fromInput([])));
        self::assertSame('🙂 Автор История', $p->process('🙂 Автор История', [$messages[0]], 'sample_channel', TextSettings::fromInput(['links' => 'remove'])));
    }

    public function testPostProviderConstraintsAndInstructionLanguagePassedThrough(): void
    {
        $provider = $this->createMock(TextProvider::class);
        $settings = TextSettings::fromInput(['mode' => 'custom', 'instruction' => 'Use a calm tone', 'language' => 'en', 'links' => 'remove', 'source_mentions' => 'remove', 'forbidden' => 'bad', 'max_length' => '10']);
        $provider->expects(self::once())->method('generate')->with('Input', $settings)->willReturn('bad@sample_channel https://example.org 🙂English123456');
        $result = (new TextProcessor($provider))->process('Input', [], 'sample_channel', $settings);
        self::assertLessThanOrEqual(10, mb_strlen($result));
        self::assertStringNotContainsString('bad', $result);
        self::assertStringNotContainsString('@sample_channel', $result);
        self::assertStringNotContainsString('https', $result);
    }

    public function testForbiddenRemovalCannotCreateEarlierPhrase(): void
    {
        $settings = TextSettings::fromInput(['forbidden' => "ab\nx"]);
        self::assertSame('', (new TextProcessor(new FakeTextProvider()))->process('axb', [], 'sample', $settings));
    }

    #[DataProvider('modes')]
    public function testFakeModes(string $mode): void
    {
        $settings = TextSettings::fromInput(['mode' => $mode, 'instruction' => 'Demo', 'max_length' => '20']);
        self::assertNotSame('', (new TextProcessor(new FakeTextProvider()))->process('  Исходный  текст ', [], 'sample', $settings));
    }

    /** @return iterable<string, array{string}> */
    public static function modes(): iterable
    {
        foreach (TextSettings::MODES as $mode) {
            yield $mode => [$mode];
        }
    }

    /** @param array<string, mixed> $input */
    #[DataProvider('invalidSettings')]
    public function testInvalidSettings(array $input): void
    {
        $this->expectException(SourceException::class);
        TextSettings::fromInput($input);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidSettings(): iterable
    {
        foreach ([['mode' => 'bad'], ['language' => 'bad'], ['mode' => []], ['max_length' => '0'], ['max_length' => '20001'], ['max_length' => '1.5'], ['links' => 'bad'], ['source_mentions' => 'bad'], ['mode' => 'custom'], ['instruction' => str_repeat('a', 4001)], ['forbidden' => str_repeat('a', 101)], ['forbidden' => implode("\n", range(1, 101))], ['language' => "\xff"]] as $i => $input) {
            yield (string) $i => [$input];
        }
    }

    public function testFakeRefusesProductionAndInvalidProviderOutputFails(): void
    {
        try {
            (new FakeTextProvider(false))->generate('text', TextSettings::fromInput(['mode' => 'edit']));
            self::fail('Production fake must refuse work');
        } catch (\RuntimeException $e) {
            self::assertSame('Text provider unavailable', $e->getMessage());
        }
        $provider = $this->createMock(TextProvider::class);
        $provider->method('generate')->willReturn("\xff");
        $this->expectException(\RuntimeException::class);
        (new TextProcessor($provider))->process('text', [], 'sample', TextSettings::fromInput(['mode' => 'edit']));
    }
}
