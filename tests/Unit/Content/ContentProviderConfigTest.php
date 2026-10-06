<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Kernel\Config;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** No adapter is activated merely because a key exists, or with an empty credential. */
final class ContentProviderConfigTest extends TestCase
{
    public function testDefaultsStayFakeEvenWhenKeysExist(): void
    {
        $config = Config::load(TestEnv::basePath() . '/config', TestEnv::env(['OPENAI_API_KEY' => 'test-key', 'TINEYE_API_KEY' => 'test-key', 'REPLICATE_API_TOKEN' => 'test-key']));
        foreach (['text', 'semantic', 'image_search', 'image_enhancement', 'video'] as $name) {
            self::assertSame('fake', $config->string('content_providers.' . $name));
        }
    }
    /** @return iterable<string,array{string,string,string}> */
    public static function providers(): iterable
    {
        yield 'text' => ['TEXT', 'openai', 'OPENAI_API_KEY'];
        yield 'semantic' => ['SEMANTIC', 'openai', 'OPENAI_API_KEY'];
        yield 'search' => ['IMAGE_SEARCH', 'tineye', 'TINEYE_API_KEY'];
        yield 'enhancement' => ['IMAGE_ENHANCEMENT', 'replicate', 'REPLICATE_API_TOKEN'];
        yield 'video' => ['VIDEO', 'replicate', 'REPLICATE_API_TOKEN'];
    }
    #[DataProvider('providers')]
    public function testRealSelectionRequiresItsCredential(string $name, string $provider, string $credential): void
    {
        $config = Config::load(TestEnv::basePath() . '/config', TestEnv::env(['CONTENT_' . $name . '_PROVIDER' => $provider, $credential => '  ']));
        self::assertFalse((new \App\Domain\Content\Automation\AutomationGuard($config))->allows(strtolower($name)));
        self::assertSame($provider, $config->string('content_providers.' . strtolower($name)));
    }
    #[DataProvider('providers')]
    public function testExplicitSelectionAndCredentialEnableRealAdapterConfig(string $name, string $provider, string $credential): void
    {
        $config = Config::load(TestEnv::basePath() . '/config', TestEnv::env(['CONTENT_' . $name . '_PROVIDER' => $provider, $credential => 'test-only-key']));
        self::assertSame($provider, $config->string('content_providers.' . strtolower($name)));
    }
    public function testDisabledEnhancementNeedsNoCredentials(): void
    {
        $config = Config::load(TestEnv::basePath() . '/config', TestEnv::env(['CONTENT_IMAGE_ENHANCEMENT_PROVIDER' => 'disabled']));
        self::assertSame('disabled', $config->string('content_providers.image_enhancement'));
    }
}
