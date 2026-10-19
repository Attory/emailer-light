<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\NativeVersionBinding;
use PHPUnit\Framework\TestCase;

final class NativeVersionBindingTest extends TestCase
{
    public function testOriginalAndUpdatedLocksHaveTheSameOperationBoundFingerprint(): void
    {
        foreach (['1.0.0', '1.1.0'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.0');
            $original = ['installation_uuid' => 'synthetic-installation', 'product_version' => $source, 'administrator_id' => 'preserved-owner'];
            $updated = [...$original, 'product_version' => '1.2.0'];
            $captured = $binding->lock(json_encode($original, JSON_THROW_ON_ERROR), true);
            self::assertSame(hash('sha256', $captured), hash('sha256', $binding->lock(json_encode($updated, JSON_THROW_ON_ERROR))));
            self::assertSame($source, $binding->canonical('1.2.0'));
            self::assertNotSame($captured, $binding->lock(json_encode([...$updated, 'administrator_id' => 'different-owner'], JSON_THROW_ON_ERROR)));
        }
    }

    public function testPatchSupportsEveryPublishedLightSourceAndRejectsOtherMetadata(): void
    {
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.3');
            $original = ['installation_uuid' => 'synthetic-installation', 'product_version' => $source];
            $expected = $binding->lock(json_encode($original, JSON_THROW_ON_ERROR), true);
            $updated = json_encode([...$original, 'product_version' => '1.2.3'], JSON_THROW_ON_ERROR);
            self::assertSame($expected, $binding->lock($updated));
            foreach (array_diff(['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.4'], [$source]) as $unrelated) {
                try {
                    $binding->canonical($unrelated);
                    self::fail('An unrelated patch source version was accepted.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('outside the authenticated update pair', $exception->getMessage());
                }
            }
        }
    }

    public function testAnAlreadyChangedLockCannotBecomeFreshSourceEvidence(): void
    {
        $this->expectExceptionMessage('source installation lock version is inconsistent');
        (new NativeVersionBinding('1.1.0', '1.2.0'))->lock('{"product_version":"1.2.0"}', true);
    }

    public function testUnrelatedDatabaseIdentityVersionIsRejectedForEachPair(): void
    {
        foreach (['1.0.0' => '1.1.0', '1.1.0' => '1.0.0'] as $source => $unrelated) {
            try {
                (new NativeVersionBinding($source, '1.2.0'))->canonical($unrelated);
                self::fail('An unrelated database identity version was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('outside the authenticated update pair', $exception->getMessage());
            }
        }
    }

    public function testUnrelatedLockVersionIsRejectedForEachPair(): void
    {
        foreach (['1.0.0' => '1.1.0', '1.1.0' => '1.0.0'] as $source => $unrelated) {
            try {
                (new NativeVersionBinding($source, '1.2.0'))->lock(json_encode(['product_version' => $unrelated], JSON_THROW_ON_ERROR));
                self::fail('An unrelated lock version was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('outside the authenticated update pair', $exception->getMessage());
            }
        }
    }
}
