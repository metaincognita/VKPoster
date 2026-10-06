<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Integrations\Images\FakeImageEnhancementProvider;
use App\Integrations\Images\FakeImageSearchProvider;
use App\Integrations\Images\ImageCandidate;
use App\Tests\Support\SourceImageFixture;
use PHPUnit\Framework\TestCase;

/** Offline providers preserve originals and cannot manufacture provenance, quality improvement or unsafe browser links. */
final class ImageProvidersTest extends TestCase
{
    public function testFakeBoundariesAndImmutableEnhancementCopy(): void
    {
        $candidate = new ImageCandidate(SourceImageFixture::bytes(), 'https://example.com/photo.jpg');
        $search = new FakeImageSearchProvider([$candidate]);
        self::assertSame([$candidate], $search->search('/unused'));
        self::assertSame('fake', $search->name());
        self::assertSame([], (new FakeImageSearchProvider())->search('/unused'));
        $path = tempnam(sys_get_temp_dir(), 'image-fake-test-');
        self::assertNotFalse($path);
        try {
            file_put_contents($path, $candidate->bytes);
            $provider = new FakeImageEnhancementProvider();
            self::assertSame('fake', $provider->name());
            self::assertSame($candidate->bytes, $provider->enhance($path));
            self::assertSame($candidate->bytes, file_get_contents($path));
            self::assertNull((new FakeImageEnhancementProvider(false))->enhance($path));
        } finally {
            unlink($path);
        }
    }

    public function testUnsafeCandidateProvenanceIsRefused(): void
    {
        foreach (['javascript:alert(1)', 'file:///etc/passwd', 'https://user:password@example.com/a.jpg', 'https://example.com/a.jpg?token=secret', '/relative.jpg'] as $url) {
            try {
                new ImageCandidate('bytes', $url);
                self::fail('Unsafe provenance');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid image provenance', $e->getMessage());
            }
        }
    }
}
