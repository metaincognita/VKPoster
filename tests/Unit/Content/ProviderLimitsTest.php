<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Integrations\ContentProviders\ProviderException;
use App\Integrations\ContentProviders\ProviderHttp;
use App\Integrations\ContentProviders\ProviderLimits;
use App\Kernel\HttpClient\HttpClientInterface;
use App\Tests\Support\FakeClock;
use App\Tests\Support\TestEnv;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ProviderLimitsTest extends TestCase
{
    private ProviderLimits $limits;
    private FakeClock $clock;
    protected function setUp(): void
    {
        TestEnv::redis()->flushDB();
        $this->clock = new FakeClock('2026-10-06 10:00:00');
        $this->limits = new ProviderLimits(TestEnv::redis(), TestEnv::config(['CONTENT_CONCURRENCY' => '1', 'CONTENT_PROVIDER_PER_MINUTE' => '2', 'CONTENT_VIDEO_PER_HOUR' => '1']), $this->clock);
    }
    private function denied(string $method = 'GET', string $url = 'https://api.openai.com/v1/responses'): void
    {
        try {
            $this->limits->acquire($method, $url);
            self::fail('Must deny');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('https:', $e->getMessage());
        }
    }
    public function testConcurrencyRateLimitAndCrashLeaseExpiry(): void
    {
        $a = $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
        $this->denied();
        $this->limits->release($a, false);
        $b = $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
        $this->limits->release($b, false);
        $this->denied();
        $this->clock->advance(61);
        $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
        $this->clock->advance(121);
        $c = $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
        $this->limits->release($c, false);
        self::assertSame(0, TestEnv::redis()->zCard('content-provider:openai:leases'));
    }
    public function testCircuitAndStrictVideoBudget(): void
    {
        for ($i = 0; $i < 3; ++$i) {
            $lease = $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
            $this->limits->release($lease, true);
            $this->clock->advance(61);
        }
        $this->denied();
        self::assertSame(3, (int) TestEnv::redis()->get('content-provider:openai:errors'));
        TestEnv::redis()->del('content-provider:openai:open');
        $lease = $this->limits->acquire('GET', 'https://api.openai.com/v1/responses');
        $this->limits->release($lease, false);
        self::assertFalse(TestEnv::redis()->get('content-provider:openai:failures'));
        $url = 'https://api.replicate.com/v1/models/bytedance/seedance-1-pro/predictions';
        $lease = $this->limits->acquire('POST', $url);
        $this->limits->release($lease, false);
        $this->denied('POST', $url);
        $lease = $this->limits->acquire('GET', 'https://api.replicate.com/v1/predictions/job');
        $this->limits->release($lease, false);
    }
    public function testUnknownHostDeniedAndRedisFailureFailsClosed(): void
    {
        $this->denied('GET', 'https://example.com');
        $limits = new ProviderLimits(new \Redis(), TestEnv::config(), $this->clock);
        $this->expectException(ProviderException::class);
        $limits->acquire('GET', 'https://api.openai.com/v1/responses');
    }
    public function testTransportTimeoutDoesNotReplayPaidPostAndReleasesLease(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::once())->method('request')->willThrowException(new \RuntimeException('SECRET_CONTENT'));
        $transport = new ProviderHttp($http, static function (int $us): void {
        }, $this->limits);
        try {
            $transport->json('POST', 'https://api.openai.com/v1/responses', []);
            self::fail('Expected failure');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('SECRET_CONTENT', $e->getMessage());
        }
        self::assertSame(0, TestEnv::redis()->zCard('content-provider:openai:leases'));
    }
    public function testServerFailureDoesNotReplayPostAnd429ThenSuccessIsBounded(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(3))->method('request')->willReturnOnConsecutiveCalls(new Response(500), new Response(429, ['Retry-After' => '0']), new Response(200, [], '{"ok":true}'));
        $limits = new ProviderLimits(TestEnv::redis(), TestEnv::config(['CONTENT_PROVIDER_PER_MINUTE' => '5']), $this->clock);
        $transport = new ProviderHttp($http, static function (int $us): void {
        }, $limits);
        try {
            $transport->json('POST', 'https://api.openai.com/v1/responses', []);
            self::fail('Expected failure');
        } catch (ProviderException) {
        }
        self::assertSame(['ok' => true], $transport->json('POST', 'https://api.openai.com/v1/responses', []));
    }
    public function testEveryRetryConsumesRequestBudget(): void
    {
        $http = $this->createMock(HttpClientInterface::class);
        $http->expects(self::exactly(2))->method('request')->willReturn(new Response(500));
        $transport = new ProviderHttp($http, static function (int $us): void {
        }, $this->limits);
        $this->expectException(ProviderException::class);
        $transport->json('GET', 'https://api.openai.com/v1/responses', []);
    }

}
