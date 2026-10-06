<?php

declare(strict_types=1);

namespace App\Tests\Support;

/** Deterministic encoded photos with edges, colour and high-frequency features; no external assets. */
final class SourceImageFixture
{
    public static function bytes(int $size = 640, int $quality = 90, bool $different = false): string
    {
        $im = new \Imagick();
        $im->newImage($size, $size, new \ImagickPixel($different ? 'red' : 'navy'));
        $draw = new \ImagickDraw();
        $draw->setFillColor(new \ImagickPixel($different ? 'black' : 'yellow'));
        for ($i = 0; $i < 8; ++$i) {
            $x = (int) ($size * $i / 8);
            $draw->rectangle($x, (int) ($size * $i / 16), $x + (int) ($size / 16), (int) ($size * (0.5 + $i / 16)));
        }
        $im->drawImage($draw);
        $im->setImageFormat('JPEG');
        $im->setImageCompressionQuality($quality);
        $bytes = $im->getImageBlob();
        $im->clear();
        return $bytes;
    }
}
