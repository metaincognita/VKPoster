<?php

declare(strict_types=1);

namespace App\Domain\Content\ImageProcessing;

use App\Domain\Media\ImageProcessor;
use App\Integrations\Storage\MediaStorage;
use Symfony\Component\Uid\Ulid;

/** Private immutable archives and sanitized previews; never re-encode the archive or expose storage keys to the browser. */
final class ImageFiles
{
    public function __construct(private readonly MediaStorage $storage, private readonly ImageProcessor $images, private readonly ?\App\Domain\Content\Operations\StorageRegistry $registry = null)
    {
    }

    public function temporary(string $bytes): string
    {
        if (strlen($bytes) < 1 || strlen($bytes) > 16777216) {
            throw new \InvalidArgumentException('Image limit');
        }
        $path = tempnam(sys_get_temp_dir(), 'source-image-');
        if ($path === false || file_put_contents($path, $bytes) !== strlen($bytes)) {
            throw new \RuntimeException('Image write failed');
        }
        chmod($path, 0600);
        return $path;
    }

    /** @return array{storage_key:string,preview_key:string,sha256:string} */
    public function save(int $workspaceId, string $path): array
    {
        $key = 'source-images/' . $workspaceId . '/' . (string) new Ulid();
        $preview = tempnam(sys_get_temp_dir(), 'source-preview-');
        if ($preview === false) {
            throw new \RuntimeException('Image write failed');
        }
        try {
            $this->images->thumbnail($path, $preview, 1024);
            foreach ([$key . '/original' => $path, $key . '/preview.webp' => $preview] as $target => $file) {
                $stream = fopen($file, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Image read failed');
                }
                try {
                    $this->registry?->track($target);
                    $this->storage->put($target, $stream);
                } finally {
                    fclose($stream);
                }
            }
            $hash = hash_file('sha256', $path);
            return ['storage_key' => $key . '/original', 'preview_key' => $key . '/preview.webp', 'sha256' => $hash === false ? throw new \RuntimeException('Image hash failed') : $hash];
        } catch (\Throwable $e) {
            $this->delete(['storage_key' => $key . '/original', 'preview_key' => $key . '/preview.webp']);
            throw $e;
        } finally {
            unlink($preview);
        }
    }

    /** @param array{storage_key:string,preview_key:string} $variant */
    public function delete(array $variant): void
    {
        $this->storage->delete($variant['storage_key']);
        $this->storage->delete($variant['preview_key']);
    }

    /** @return resource */
    public function preview(string $key): mixed
    {
        return $this->storage->read($key);
    }
}
