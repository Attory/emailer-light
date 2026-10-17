<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\NativeAssetInventory;
use PHPUnit\Framework\TestCase;

final class NativeAssetInventoryTest extends TestCase
{
    public function testNeverUploadedTemplatesRemainEmptyWithoutCreatingFilesDuringUpdate(): void
    {
        $private = sys_get_temp_dir().'/emailer-empty-assets-'.bin2hex(random_bytes(6));
        mkdir($private, 0o700);
        $assets = $private.'/template-assets';
        try {
            $before = NativeAssetInventory::capture(['template-assets' => $assets], $private);
            self::assertSame($before, NativeAssetInventory::capture(['template-assets' => $assets], $private));
            self::assertFalse(file_exists($assets));
            mkdir($assets, 0o700);
            self::assertNotSame($before, NativeAssetInventory::capture(['template-assets' => $assets], $private));
            file_put_contents($assets.'/image.png', 'synthetic asset');
            $populated = NativeAssetInventory::capture(['template-assets' => $assets], $private);
            file_put_contents($assets.'/image.png', 'changed synthetic asset');
            self::assertNotSame($populated, NativeAssetInventory::capture(['template-assets' => $assets], $private));
            unlink($assets.'/image.png');
            rmdir($assets);
            symlink($private, $assets);
            try {
                NativeAssetInventory::capture(['template-assets' => $assets], $private);
                self::fail('A linked asset directory was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('missing or linked', $error->getMessage());
            }
            unlink($assets);
            foreach (['unrelated' => $private.'/unrelated', 'template-assets' => $private.'/../outside'] as $name => $path) {
                try {
                    NativeAssetInventory::capture([$name => $path], $private);
                    self::fail('An arbitrary absent asset root was accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('missing or linked', $error->getMessage());
                }
            }
        } finally {
            if (is_link($assets)) {
                unlink($assets);
            }
            rmdir($private);
        }
    }
}
