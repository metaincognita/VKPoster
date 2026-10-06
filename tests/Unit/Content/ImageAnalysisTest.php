<?php

declare(strict_types=1);

namespace App\Tests\Unit\Content;

use App\Domain\Content\ImageProcessing\ImageAnalysis;
use App\Domain\Media\ImageProcessor;
use App\Domain\Media\MediaLimits;
use App\Tests\Support\SourceImageFixture;
use App\Tests\Support\TestEnv;
use PHPUnit\Framework\TestCase;

/** Actual Imagick quality and conservative correspondence tests, including perceptual-hash false positives. */
final class ImageAnalysisTest extends TestCase
{
    public function testQualityCompressionDimensionsAndVerifiedHigherResolution(): void
    {
        $analysis = new ImageAnalysis(new ImageProcessor(MediaLimits::fromConfig(TestEnv::config())));
        $paths = [];
        try {
            $results = [];
            foreach ([[320, 90], [640, 90], [1600, 90], [640, 20]] as [$size, $quality]) {
                $path = tempnam(sys_get_temp_dir(), 'image-test-');
                self::assertNotFalse($path);
                $paths[] = $path;
                file_put_contents($path, SourceImageFixture::bytes($size, $quality));
                $results[] = $analysis->inspect($path);
            }
            self::assertSame(['poor', 'acceptable', 'good', 'poor'], array_column($results, 'quality'));
            self::assertSame(1600, $results[2]['width']);
            self::assertSame('image/jpeg', $results[2]['mime']);
            self::assertSame('verified', $analysis->match($results[1], $results[2])['status']);
            $other = tempnam(sys_get_temp_dir(), 'image-test-');
            self::assertNotFalse($other);
            $paths[] = $other;
            file_put_contents($other, SourceImageFixture::bytes(1600, different: true));
            $match = $analysis->match($results[1], $analysis->inspect($other));
            self::assertSame('mismatch', $match['status']);
            self::assertLessThan(0.9, $match['confidence']);
            $collision = $analysis->inspect($other);
            $collision['hash'] = $results[1]['hash']; // Simulated perceptual-hash collision.
            self::assertSame('mismatch', $analysis->match($results[1], $collision)['status']);
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }
    }

    public function testTransparentCandidateCannotPassWithHiddenMatchingRgb(): void
    {
        $analysis = new ImageAnalysis(new ImageProcessor(MediaLimits::fromConfig(TestEnv::config())));
        $original = tempnam(sys_get_temp_dir(), 'image-original-');
        $candidate = tempnam(sys_get_temp_dir(), 'image-candidate-');
        self::assertNotFalse($original);
        self::assertNotFalse($candidate);
        try {
            file_put_contents($original, SourceImageFixture::bytes());
            $im = new \Imagick($original);
            $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_ACTIVATE);
            $im->evaluateImage(\Imagick::EVALUATE_SET, 0, \Imagick::CHANNEL_ALPHA);
            $im->setImageFormat('PNG');
            $im->writeImage($candidate);
            $im->clear();
            $data = $analysis->inspect($candidate);
            self::assertSame(1, $data['metrics']['has_alpha']);
            self::assertSame('mismatch', $analysis->match($analysis->inspect($original), $data)['status']);
        } finally {
            unlink($original);
            unlink($candidate);
        }
    }

    public function testLowTextureAndCorruptFilesDoNotClaimReliableMatch(): void
    {
        $analysis = new ImageAnalysis(new ImageProcessor(MediaLimits::fromConfig(TestEnv::config())));
        $path = tempnam(sys_get_temp_dir(), 'image-test-');
        self::assertNotFalse($path);
        try {
            $im = new \Imagick();
            $im->newImage(1200, 1200, 'white', 'PNG');
            $im->writeImage($path);
            $im->clear();
            $data = $analysis->inspect($path);
            self::assertSame('needs_review', $analysis->match($data, $data)['status']);
            file_put_contents($path, 'not an image');
            $this->expectException(\InvalidArgumentException::class);
            $analysis->inspect($path);
        } finally {
            unlink($path);
        }
    }
}
