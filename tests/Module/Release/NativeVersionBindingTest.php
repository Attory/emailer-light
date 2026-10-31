<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\NativeVersionBinding;
use PHPUnit\Framework\TestCase;

final class NativeVersionBindingTest extends TestCase
{
    public function testTraversalRepairSupportsAffectedSourceWithoutRelabellingSchema(): void
    {
        $binding = new NativeVersionBinding('1.2.11', '1.2.12');
        $source = ['installation_uuid' => 'unchanged', 'product_version' => '1.2.11', 'schema_version' => '20261025000000'];
        self::assertSame('20261025000000', $binding->sourceSchema());
        self::assertSame($binding->lock(json_encode($source, JSON_THROW_ON_ERROR), true), $binding->lock(json_encode([...$source, 'product_version' => '1.2.12'], JSON_THROW_ON_ERROR)));
    }

    public function testPostCorePauseFollowupAcceptsExactPublishedSources(): void
    {
        foreach (['1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.2.10'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.11');
            $lock = ['installation_uuid' => 'unchanged', 'product_version' => $source, 'schema_version' => '20261025000000'];
            self::assertSame($binding->lock(json_encode($lock, JSON_THROW_ON_ERROR), true), $binding->lock(json_encode([...$lock, 'product_version' => '1.2.11'], JSON_THROW_ON_ERROR)));
        }
    }

    public function testRecoveryIntentFollowupPreservesCurrentSourceSchemaAndExactIdentity(): void
    {
        foreach (['1.2.6', '1.2.7', '1.2.8', '1.2.9'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.10');
            $original = ['installation_uuid' => 'unchanged', 'product_version' => $source, 'schema_version' => '20261025000000'];
            $before = $binding->lock(json_encode($original, JSON_THROW_ON_ERROR), true);
            self::assertSame('20261025000000', $binding->sourceSchema());
            self::assertSame($before, $binding->lock(json_encode([...$original, 'product_version' => '1.2.10'], JSON_THROW_ON_ERROR)));
        }
    }

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

    public function testUbuntu26PatchSupportsAllPublishedSourcesWithoutWeakeningVersionBinding(): void
    {
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.4');
            $original = ['installation_uuid' => 'synthetic-installation', 'product_version' => $source];
            $expected = $binding->lock(json_encode($original, JSON_THROW_ON_ERROR), true);
            $updated = json_encode([...$original, 'product_version' => '1.2.4'], JSON_THROW_ON_ERROR);
            self::assertSame($expected, $binding->lock($updated));
            foreach (array_diff(['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.5'], [$source]) as $unrelated) {
                try {
                    $binding->canonical($unrelated);
                    self::fail('An unrelated patch source version was accepted.');
                } catch (\RuntimeException $exception) {
                    self::assertStringContainsString('outside the authenticated update pair', $exception->getMessage());
                }
            }
        }
    }

    public function testEditorPatchSupportsAllPublishedSourcesWithoutWeakeningVersionBinding(): void
    {
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.5');
            $original = ['installation_uuid' => 'synthetic-installation', 'product_version' => $source];
            $expected = $binding->lock(json_encode($original, JSON_THROW_ON_ERROR), true);
            $updated = json_encode([...$original, 'product_version' => '1.2.5'], JSON_THROW_ON_ERROR);
            self::assertSame($expected, $binding->lock($updated));
            foreach (array_diff(['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4', '1.2.6'], [$source]) as $unrelated) {
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

    public function testUpdateTo127BindsEveryPriorSourceToItsActualSchemaWithoutCoercing126BackToTheOlderSchema(): void
    {
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4', '1.2.5', '1.2.6'] as $source) {
            $binding = new NativeVersionBinding($source, '1.2.7');
            $sourceSchema = '1.2.6' === $source ? '20261025000000' : '20261014000000';
            self::assertSame($sourceSchema, $binding->sourceSchema());
            $sourceLock = ['installation_uuid' => 'synthetic-installation', 'product_version' => $source, 'schema_version' => $sourceSchema, 'administrator_id' => 'preserved-owner'];
            $targetLock = [...$sourceLock, 'product_version' => '1.2.7', 'schema_version' => '20261025000000'];
            self::assertSame($binding->lock(json_encode($sourceLock, JSON_THROW_ON_ERROR), true), $binding->lock(json_encode($targetLock, JSON_THROW_ON_ERROR)));
            try {
                $binding->lock(json_encode([...$targetLock, 'schema_version' => '20261014000000'], JSON_THROW_ON_ERROR));
                self::fail('A target with a mismatched schema was accepted.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('not a permitted pair', $failure->getMessage());
            }
        }
        $this->expectException(\RuntimeException::class);
        (new NativeVersionBinding('1.2.6', '1.2.7'))->lock('{"product_version":"1.2.6","schema_version":"20261014000000"}', true);
    }
}
