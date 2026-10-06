<?php

declare(strict_types=1);

namespace App\Domain\Content\ImageProcessing;

use App\Domain\Media\ImageProcessor;
use App\Domain\Media\MediaService;
use Imagick;

/** Technical heuristics, not aesthetic/semantic judgments. Versioned metrics make thresholds explainable. */
final class ImageAnalysis
{
    public function __construct(private readonly ImageProcessor $images)
    {
    }

    /** @return array{width:int,height:int,mime:string,bytes:int,quality:string,metrics:array<string,int|float|string|null>,hash:string,rgb:list<float>} */
    public function inspect(string $path): array
    {
        $mime = MediaService::sniff($path);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Unsupported image');
        }
        $info = $this->images->inspect($path, $mime);
        $bytes = filesize($path);
        if ($bytes === false || $bytes < 1 || $bytes > 16777216 || $info->width * $info->height > 40000000) {
            throw new \InvalidArgumentException('Image limit');
        }
        $im = new Imagick($path);
        $im->autoOrient();
        $im->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        $width = $im->getImageWidth();
        $height = $im->getImageHeight();
        $compression = $mime === 'image/jpeg' ? $im->getImageCompressionQuality() : null;
        // Compare visible pixels, not hidden RGB behind transparency (which could falsely match an opaque original).
        $hasAlpha = $im->getImageAlphaChannel();
        if ($hasAlpha) {
            $im->setImageBackgroundColor('white');
            $visible = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->clear();
            $im = $visible;
        }
        $rgbImage = clone $im;
        $rgbImage->resizeImage(32, 32, Imagick::FILTER_LANCZOS, 1);
        $rgb = array_map(static fn (int|float $v): float => (float) $v, $rgbImage->exportImagePixels(0, 0, 32, 32, 'RGB', Imagick::PIXEL_DOUBLE));
        $rgbImage->clear();
        $gray = clone $im;
        $gray->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $gray->resizeImage(9, 8, Imagick::FILTER_LANCZOS, 1);
        $pixels = $gray->exportImagePixels(0, 0, 9, 8, 'I', Imagick::PIXEL_DOUBLE);
        $hash = '';
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $hash .= $pixels[$y * 9 + $x] > $pixels[$y * 9 + $x + 1] ? '1' : '0';
            }
        }
        $gray->clear();
        $im->transformImageColorspace(Imagick::COLORSPACE_GRAY);
        $im->resizeImage(128, 128, Imagick::FILTER_LANCZOS, 1);
        $p = $im->exportImagePixels(0, 0, 128, 128, 'I', Imagick::PIXEL_DOUBLE);
        $mean = array_sum($p) / count($p);
        $contrast = 0.0;
        $lap = [];
        foreach ($p as $v) {
            $contrast += ($v - $mean) ** 2;
        }
        for ($y = 1; $y < 127; ++$y) {
            for ($x = 1; $x < 127; ++$x) {
                $i = $y * 128 + $x;
                $lap[] = 4 * $p[$i] - $p[$i - 1] - $p[$i + 1] - $p[$i - 128] - $p[$i + 128];
            }
        }
        $avg = array_sum($lap) / count($lap);
        $blur = array_sum(array_map(static fn (float $v): float => ($v - $avg) ** 2, $lap)) / count($lap) * 65025;
        $contrast = sqrt($contrast / count($p));
        $im->clear();
        $compressionPoor = $compression !== null && $compression > 0 && $compression < 45;
        $blurPoor = $contrast > 0.04 && $blur < 8;
        $quality = min($width, $height) < 400 || $compressionPoor || $blurPoor ? 'poor' : (min($width, $height) >= 1000 && $contrast > 0.04 && $blur >= 20 ? 'good' : 'acceptable');
        return ['width' => $width, 'height' => $height, 'mime' => $mime, 'bytes' => $bytes, 'quality' => $quality,
            'metrics' => ['version' => 1, 'has_alpha' => $hasAlpha ? 1 : 0, 'jpeg_quality_estimate' => $compression, 'blur_variance' => round($blur, 3), 'contrast' => round($contrast, 5), 'bits_per_pixel' => round($bytes * 8 / ($width * $height), 3), 'low_texture' => $contrast <= 0.04 ? 1 : 0],
            'hash' => $hash, 'rgb' => $rgb];
    }

    /**
     * Conservative dHash + independent normalized RGB RMSE + aspect check. Low-texture images always need human review.
     * @param array{width:int,height:int,hash:string,rgb:list<float>,metrics:array<string,int|float|string|null>} $original
     * @param array{width:int,height:int,hash:string,rgb:list<float>,metrics:array<string,int|float|string|null>} $candidate
     * @return array{status:string,confidence:float,distance:int,rmse:float,aspect_delta:float}
     */
    public function match(array $original, array $candidate): array
    {
        $distance = 0;
        for ($i = 0; $i < 64; ++$i) {
            $distance += $original['hash'][$i] === $candidate['hash'][$i] ? 0 : 1;
        }
        $sum = 0.0;
        foreach ($original['rgb'] as $i => $v) {
            $sum += ($v - $candidate['rgb'][$i]) ** 2;
        }
        $rmse = sqrt($sum / count($original['rgb']));
        $aspect = abs(log(($original['width'] / $original['height']) / ($candidate['width'] / $candidate['height'])));
        $confidence = max(0.0, min(1.0, 1 - $distance / 64 * 0.4 - $rmse * 1.5 - min(1.0, $aspect) * 0.3));
        $status = $distance <= 4 && $rmse < 0.045 && $aspect < 0.02 && $original['metrics']['low_texture'] === 0 && $candidate['metrics']['low_texture'] === 0 ? 'verified' : ($distance > 16 || $rmse > 0.2 || $aspect > 0.15 ? 'mismatch' : 'needs_review');
        return ['status' => $status, 'confidence' => round($confidence, 5), 'distance' => $distance, 'rmse' => round($rmse, 5), 'aspect_delta' => round($aspect, 5)];
    }
}
