<?php

declare(strict_types=1);

namespace App\Tests\Unit\Discovery;

use App\Domain\ContentDiscovery\DiscoveryClustering;
use App\Domain\ContentDiscovery\DiscoveryIdentity;
use App\Domain\ContentDiscovery\DiscoveryItem;
use App\Domain\ContentDiscovery\TrendScore;
use App\Integrations\Discovery\FakeSocialDiscoveryProvider;
use App\Integrations\Discovery\FakeTelegramDiscoveryProvider;
use App\Integrations\Discovery\FakeWebNewsDiscoveryProvider;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DiscoveryCoreTest extends TestCase
{
    public function testCanonicalIdentitiesAndIndependentPublishers(): void
    {
        self::assertSame('https://example.com/news?a=1&b=2', DiscoveryIdentity::url('https://EXAMPLE.com:443/news/?b=2&utm_medium=x&a=1&fbclid=x#fragment'));
        $now = new DateTimeImmutable('2026-10-06T00:00:00Z');
        $a = new DiscoveryItem('test', 'web', 'desk.a', 'Desk A', 'https://example.com/news', '1', 'Orbital telescope: SAME Event!', 'Summary', $now);
        $b = new DiscoveryItem('test', 'web', 'desk.b', 'Desk B', 'https://example.com/other', '1', 'Orbital telescope same event', 'Summary', $now);
        self::assertSame('orbital telescope same event', DiscoveryIdentity::title($a->title));
        self::assertNotSame(DiscoveryIdentity::keys($a)['title'], DiscoveryIdentity::keys($b)['title']);
        self::assertNotSame(DiscoveryIdentity::keys($a)['external'], DiscoveryIdentity::keys($b)['external']);
        self::assertCount(4, DiscoveryIdentity::keys($a));
        self::assertCount(2, DiscoveryIdentity::keys(new DiscoveryItem('test', 'web', 'desk.a', 'A', 'https://example.com/', null, 'Title', '', null)));
    }
    /** @return iterable<array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach (['javascript:alert(1)', 'http://example.com/', 'https://user:password@example.com/', 'https://127.0.0.1/', 'https://localhost/', 'https://example.com:8080/', "https://example.com/\n", 'https://example.com/' . str_repeat('a', 2048)] as $url) {
            yield [$url];
        }
    }
    #[DataProvider('unsafeUrls')]
    public function testUnsafeUrlRejected(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DiscoveryIdentity::url($url);
    }
    public function testClusteringThresholdTimeAndNumbers(): void
    {
        $cluster = new DiscoveryClustering();
        $a = $cluster->tokens('Today the orbital telescope detected water planet 2026');
        self::assertNotContains('today', $a);
        self::assertSame(1.0, $cluster->similarity($a, $a, 1));
        self::assertSame(0.0, $cluster->similarity($a, $a, 37));
        self::assertSame(0.0, $cluster->similarity([], $a, 0));
        self::assertSame(0.0, $cluster->similarity(['only'], ['only'], 0));
        self::assertSame(0.0, $cluster->similarity($a, $cluster->tokens('orbital telescope detected water planet 2025'), 0));
    }
    public function testScoreComponentsNeverInventUnknownMetrics(): void
    {
        $now = new DateTimeImmutable('2026-10-06T00:00:00Z');
        $score = new TrendScore();
        $unknown = $score->calculate([['source_key' => 'a', 'published_at' => null, 'discovered_at' => '2026-10-05 00:00:00', 'metadata_json' => '{}', 'engagement_json' => '{}']], $now);
        self::assertSame(0, $unknown['score']);
        self::assertSame(35, $unknown['coverage']);
        foreach (['freshness', 'engagement', 'authority', 'relative_growth'] as $key) {
            self::assertNull($unknown['components'][$key]['value']);
        }
        $items = [];
        foreach (range(1, 5) as $i) {
            $items[] = ['source_key' => (string) $i, 'published_at' => '2026-10-06 00:00:00', 'discovered_at' => '2026-10-06 00:00:00', 'metadata_json' => '{"authority":1}', 'engagement_json' => '{"views":10000,"baseline_views":100,"baseline_comparable":true,"window_hours":6}'];
        }
        $complete = $score->calculate($items, $now);
        self::assertSame(100, $complete['score']);
        self::assertSame(100, $complete['coverage']);
        self::assertSame(5, $complete['components']['independent_sources']['count']);
        $later = $score->calculate($items, $now->modify('+72 hours'));
        self::assertSame(0.0, $later['components']['freshness']['value']);
        self::assertSame(0, $later['components']['velocity']['value']);
        $items[0]['engagement_json'] = '{"views":1,"baseline_views":0,"baseline_comparable":true}';
        self::assertNull($score->calculate([$items[0]], $now)['components']['relative_growth']['value']);
        $items[0]['engagement_json'] = '{"views":1,"baseline_views":100,"baseline_comparable":true,"window_hours":6}';
        self::assertSame(0.0, $score->calculate([$items[0]], $now)['components']['relative_growth']['value']);
    }
    public function testThreeFakeProvidersAndProductionDisable(): void
    {
        $now = new DateTimeImmutable('2026-10-06T00:00:00Z');
        $providers = [new FakeTelegramDiscoveryProvider(), new FakeWebNewsDiscoveryProvider(), new FakeSocialDiscoveryProvider()];
        self::assertSame([1, 2, 1], array_map(static fn ($p): int => count($p->discover($now)), $providers));
        self::assertSame(['telegram', 'web', 'social'], array_map(static fn ($p): string => $p->sourceType(), $providers));
        $this->expectException(\RuntimeException::class);
        (new FakeTelegramDiscoveryProvider(false))->discover($now);
    }
    /** @return iterable<array{array<string,mixed>}> */
    public static function invalidItems(): iterable
    {
        yield [['provider' => 'INVALID SECRET']];
        yield [['sourceType' => 'instagram']];
        yield [['sourceKey' => '']];
        yield [['sourceName' => '']];
        yield [['title' => '']];
        yield [['excerpt' => str_repeat('x', 2001)]];
        yield [['externalId' => '']];
        yield [['language' => 'INVALID']];
        yield [['metadata' => ['password' => 'not allowed']]];
        yield [['metadata' => ['authority' => 2]]];
        yield [['engagement' => ['views' => -1]]];
        yield [['engagement' => ['baseline_comparable' => 'yes']]];
        yield [['title' => "\xff"]];
    }
    /** @param array<string,mixed> $overrides */
    #[DataProvider('invalidItems')]
    public function testInvalidItemMetadata(array $overrides): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $args = array_replace(['provider' => 'test', 'sourceType' => 'web', 'sourceKey' => 'a', 'sourceName' => 'A', 'url' => 'https://example.com/', 'externalId' => null, 'title' => 'Story', 'excerpt' => '', 'publishedAt' => null], $overrides);
        new DiscoveryItem(...$args);
    }
}
