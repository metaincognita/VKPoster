<?php

declare(strict_types=1);

namespace App\Tests\Unit\Source;

use App\Domain\Source\SourceException;
use App\Domain\Source\TelegramSourceLocator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TelegramSourceLocator::class)]
#[CoversClass(SourceException::class)]
final class TelegramSourceLocatorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function valid(): iterable
    {
        yield 'handle' => [' @Sample_Channel ', 'sample_channel'];
        yield 'bare username' => ['Sample_Channel', 'sample_channel'];
        yield 'URL' => ['https://t.me/Sample_Channel', 'sample_channel'];
        yield 'trailing slash' => ['https://T.ME/Sample_Channel/', 'sample_channel'];
        yield 'short URL' => ['t.me/Sample_Channel', 'sample_channel'];
        yield 'minimum length' => ['@abcde', 'abcde'];
        yield 'underscore suffix' => ['@channel_', 'channel_'];
        yield 'maximum length' => ['@' . str_repeat('a', 32), str_repeat('a', 32)];
    }

    #[DataProvider('valid')]
    public function testEquivalentReferencesHaveOneCanonicalUsername(string $reference, string $expected): void
    {
        self::assertSame($expected, (new TelegramSourceLocator())->normalize($reference));
    }

    /** @return iterable<string, array{string}> */
    public static function invalid(): iterable
    {
        foreach (['', '@abcd', '@12345', '@_abcde', '@' . str_repeat('a', 33), 'https://t.me/sample/123', 'https://t.me/+abcdef', 'https://t.me/joinchat/abcdef', 'https://t.me/c/123/456', 'https://evil.test/sample', 'http://t.me/sample', 'https://t.me.evil.test/sample', 'https://user:pass@t.me/sample', 'https://t.me/sample?x=1', 'https://t.me/sample#post', "@sample\nextra", 'javascript:alert(1)'] as $reference) {
            yield $reference => [$reference];
        }
    }

    #[DataProvider('invalid')]
    public function testUnsupportedReferencesFailWithoutFetchingURLs(string $reference): void
    {
        $this->expectException(SourceException::class);
        (new TelegramSourceLocator())->normalize($reference);
    }
}
