<?php

declare(strict_types=1);

namespace App\Tests\Module\Templates;

use App\Module\Templates\Infrastructure\TemplateAssetStore;
use PHPUnit\Framework\TestCase;

final class TemplateAssetStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-template-assets-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            $entries = scandir($this->directory);
            self::assertIsArray($entries);
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    unlink($this->directory.'/'.$entry);
                }
            }
            rmdir($this->directory);
        }
    }

    public function testRasterIsDecodedReencodedAndStoredUnderARandomName(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'emailer-image-source-');
        self::assertIsString($source);
        $image = imagecreatetruecolor(3, 2);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $source);
        unset($image);
        $store = new TemplateAssetStore($this->directory);
        try {
            $stored = $store->store($source);
            self::assertMatchesRegularExpression('/^[a-f0-9]{48}\.png$/', $stored['filename']);
            self::assertSame('image/png', $stored['media_type']);
            self::assertSame(3, $stored['width']);
            self::assertSame(2, $stored['height']);
            self::assertSame($stored['content_sha256'], hash_file('sha256', $this->directory.'/'.$stored['filename']));
        } finally {
            unlink($source);
        }
    }

    public function testSvgSymlinksAndOversizedDimensionsAreRejected(): void
    {
        $store = new TemplateAssetStore($this->directory);
        $svg = tempnam(sys_get_temp_dir(), 'emailer-svg-source-');
        self::assertIsString($svg);
        file_put_contents($svg, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $link = $svg.'.link';
        symlink($svg, $link);
        try {
            foreach ([$svg, $link] as $path) {
                try {
                    $store->store($path);
                    self::fail('An unsafe image upload was accepted.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
            try {
                $store->delete('../'.str_repeat('a', 48).'.png');
                self::fail('A traversal-shaped asset filename was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        } finally {
            unlink($link);
            unlink($svg);
        }

        $oversized = tempnam(sys_get_temp_dir(), 'emailer-image-source-');
        self::assertIsString($oversized);
        $image = imagecreatetruecolor(2401, 1);
        self::assertInstanceOf(\GdImage::class, $image);
        imagepng($image, $oversized);
        unset($image);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $store->store($oversized);
        } finally {
            unlink($oversized);
        }
    }
}
