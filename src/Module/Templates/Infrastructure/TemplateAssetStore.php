<?php

declare(strict_types=1);

namespace App\Module\Templates\Infrastructure;

use App\Module\Templates\Application\Contract\TemplateAssetStorage;

final readonly class TemplateAssetStore implements TemplateAssetStorage
{
    private const MAX_BYTES = 2_097_152;
    private const MAX_DIMENSION = 2400;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private string $directory)
    {
    }

    public function store(string $sourcePath): array
    {
        $stat = @lstat($sourcePath);
        if (false === $stat || !is_file($sourcePath) || is_link($sourcePath) || 1 !== $stat['nlink'] || $stat['size'] > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Image upload is invalid.');
        }
        $quarantine = $this->quarantine($sourcePath, $stat);
        try {
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($quarantine);
            $dimensions = @getimagesize($quarantine);
            if (!\is_string($mime) || !isset(self::TYPES[$mime]) || false === $dimensions || $dimensions[0] < 1 || $dimensions[1] < 1 || $dimensions[0] > self::MAX_DIMENSION || $dimensions[1] > self::MAX_DIMENSION) {
                throw new \InvalidArgumentException('Image content or dimensions are invalid.');
            }
            $image = match ($mime) {
                'image/jpeg' => @imagecreatefromjpeg($quarantine),
                'image/png' => @imagecreatefrompng($quarantine),
                'image/webp' => @imagecreatefromwebp($quarantine),
            };
            if (!$image instanceof \GdImage) {
                throw new \InvalidArgumentException('Image could not be safely decoded.');
            }
            $this->ensureDirectory();
            $filename = bin2hex(random_bytes(24)).'.'.self::TYPES[$mime];
            $target = rtrim($this->directory, '/').'/'.$filename;
            $written = match ($mime) {
                'image/jpeg' => imagejpeg($image, $target, 88),
                'image/png' => imagepng($image, $target, 7),
                'image/webp' => imagewebp($image, $target, 88),
            };
            unset($image);
            if (!$written) {
                @unlink($target);
                throw new \RuntimeException('Image could not be stored.');
            }
            @chmod($target, 0644);
            $size = filesize($target);
            $digest = hash_file('sha256', $target);
            if (false === $size || false === $digest) {
                @unlink($target);
                throw new \RuntimeException('Stored image could not be verified.');
            }
        } finally {
            @unlink($quarantine);
        }

        return ['filename' => $filename, 'media_type' => $mime, 'byte_size' => $size, 'width' => $dimensions[0], 'height' => $dimensions[1], 'content_sha256' => $digest, 'url' => '/template-assets/'.$filename];
    }

    public function delete(string $filename): void
    {
        if (1 !== preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/', $filename)) {
            throw new \InvalidArgumentException('Template asset filename is invalid.');
        }
        @unlink(rtrim($this->directory, '/').'/'.$filename);
    }

    public function publicPath(string $filename): string
    {
        if (1 !== preg_match('/^[a-f0-9]{48}\.(jpg|png|webp)$/', $filename)) {
            throw new \InvalidArgumentException('Template asset filename is invalid.');
        }
        $root = realpath($this->directory);
        $path = realpath(rtrim($this->directory, '/').'/'.$filename);
        $stat = false === $path ? false : @lstat($path);
        if (false === $root || false === $path || !str_starts_with($path.'/', rtrim($root, '/').'/') || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('Template asset is unavailable.');
        }

        return $path;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0755, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Template asset directory is unavailable.');
        }
        if (is_link($this->directory)) {
            throw new \RuntimeException('Template asset directory is unsafe.');
        }
    }

    /** @param array<int|string, int> $expectedStat */
    private function quarantine(string $sourcePath, array $expectedStat): string
    {
        $input = @fopen($sourcePath, 'rb');
        $quarantine = tempnam(sys_get_temp_dir(), 'emailer-image-upload-');
        if (false === $input || false === $quarantine) {
            if (\is_resource($input)) {
                fclose($input);
            }
            if (\is_string($quarantine)) {
                @unlink($quarantine);
            }
            throw new \RuntimeException('Image upload could not be isolated.');
        }
        $openedStat = fstat($input);
        $output = @fopen($quarantine, 'w+b');
        if (false === $openedStat || false === $output || $openedStat['dev'] !== $expectedStat['dev'] || $openedStat['ino'] !== $expectedStat['ino'] || 1 !== $openedStat['nlink']) {
            fclose($input);
            if (\is_resource($output)) {
                fclose($output);
            }
            @unlink($quarantine);
            throw new \RuntimeException('Image upload changed during validation.');
        }
        @chmod($quarantine, 0600);
        $copied = stream_copy_to_stream($input, $output, self::MAX_BYTES + 1);
        fclose($input);
        fclose($output);
        if (false === $copied || $copied > self::MAX_BYTES || $copied !== $openedStat['size']) {
            @unlink($quarantine);
            throw new \RuntimeException('Image upload exceeds its safe bound.');
        }

        return $quarantine;
    }
}
