<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Domain\Content\VideoProcessing\VideoSettings;
use App\Domain\Source\SourceException;
use App\Integrations\Video\FakeVideoProvider;
use App\Integrations\Video\VideoInput;
use App\Integrations\Video\VideoResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Ulid;

final class VideoSettingsTest extends TestCase
{
    public function testSettingsRoundTripAndFakeNeverCreatesAsset(): void
    {
        $id = (string) new Ulid();
        $settings = VideoSettings::fromInput(['aspect_ratio' => '1:1', 'duration' => '60', 'instruction' => '  Test  ', 'text_version' => $id, 'image_version' => $id]);
        self::assertSame('Test', $settings->instruction);
        self::assertEquals($settings, VideoSettings::fromInput($settings->form()));
        $provider = new FakeVideoProvider();
        self::assertSame('fake', $provider->name());
        self::assertSame(['demonstration' => true, 'storage_key' => null], $provider->generate(new VideoInput($id, '1:1', 60, 'Test', 'Original', null))->snapshot());
        self::assertSame('source-videos/1/result', (new VideoResult(false, 'source-videos/1/result'))->storageKey);
    }
    public function testFakeIsUnavailableInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        (new FakeVideoProvider(false))->generate(new VideoInput('job', '9:16', 10, '', '', null));
    }
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'ratio' => [['aspect_ratio' => '4:3']];
        yield 'zero' => [['duration' => '0']];
        yield 'too long' => [['duration' => '61']];
        yield 'float' => [['duration' => '1.5']];
        yield 'array' => [['instruction' => ['secret']]];
        yield 'instruction' => [['instruction' => str_repeat('Я', 4001)]];
        yield 'text ref' => [['text_version' => '123']];
        yield 'image ref' => [['image_version' => 'https://example.com']];
        yield 'encoding' => [['instruction' => "\xff"]];
    }
    /** @param array<string,mixed> $input */
    #[DataProvider('invalid')]
    public function testInvalidSettings(array $input): void
    {
        $this->expectException(SourceException::class);
        VideoSettings::fromInput($input);
    }
    /** @return iterable<array{bool,?string}> */
    public static function invalidResults(): iterable
    {
        yield [false, null];
        yield [false, ''];
        yield [true, 'source-videos/a'];
        yield [false, 'https://example.com/password'];
        yield [false, 'source-videos/../secret'];
        yield [false, 'source-videos/' . str_repeat('a', 513)];
    }
    #[DataProvider('invalidResults')]
    public function testResultRejectsUnsafeAssets(bool $fake, ?string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new VideoResult($fake, $key);
    }
}
