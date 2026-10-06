<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Domain\Content\Automation\AutomationGuard;
use App\Domain\Content\Automation\AutomationSettings;
use App\Domain\Source\SourceException;
use App\Kernel\Config;
use App\Kernel\Env;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AutomationSettingsTest extends TestCase
{
    public function testDefaultsDisableEveryCostlyAction(): void
    {
        $v = AutomationSettings::defaults();
        foreach (['enabled', 'auto_selection', 'semantic_selection', 'auto_text_processing', 'auto_image_processing', 'auto_video_generation', 'auto_draft', 'discovery_enabled'] as $key) {
            self::assertFalse($v[$key]);
        }
        self::assertTrue($v['manual_review_fallback']);
    }
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalid(): iterable
    {
        yield 'video coercion' => [['auto_video_generation' => 'yes']];
        yield 'unknown mode' => [['text_mode' => 'publish']];
        yield 'frequency lower bound' => [['interval_minutes' => 0]];
        yield 'frequency upper bound' => [['interval_minutes' => 1441]];
        yield 'limit' => [['candidate_limit' => 101]];
        yield 'provider injection' => [['provider_types' => ['sql']]];
        yield 'empty providers' => [['provider_types' => []]];
    }
    /** @param array<string,mixed> $input */
    #[DataProvider('invalid')]
    public function testInvalidPolicyRejected(array $input): void
    {
        $this->expectException(SourceException::class);
        AutomationSettings::validate($input);
    }
    public function testMissingCredentialsAndProductionFakesCannotCallProviders(): void
    {
        $env = new Env([]);
        $guard = new AutomationGuard(new Config(['app' => ['env' => 'production'], 'content_providers' => ['text' => 'openai', 'semantic' => 'openai', 'image_search' => 'tineye', 'image_enhancement' => 'replicate', 'video' => 'replicate']], $env));
        foreach (['text', 'semantic', 'image_search', 'image_enhancement', 'video'] as $op) {
            self::assertFalse($guard->allows($op));
        }
        self::assertFalse($guard->discovery());
        $fake = new AutomationGuard(new Config(['app' => ['env' => 'production'], 'content_providers' => ['video' => 'fake']], $env));
        self::assertFalse($fake->allows('video'));
    }
}
