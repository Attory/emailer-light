<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use App\Module\Secrets\Application\Contract\DirectoryDurability;
use App\Module\Secrets\Application\Contract\FileDurability;
use App\Module\Secrets\Infrastructure\FilesystemMasterKey;
use PHPUnit\Framework\TestCase;

final class FilesystemMasterKeyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/emailer-key-test-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700));
        self::assertTrue(mkdir($this->root.'/release', 0o700));
        self::assertTrue(mkdir($this->root.'/release/public', 0o700));
        self::assertTrue(mkdir($this->root.'/external', 0o700));
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCreationProducesRestrictiveExternalKey(): void
    {
        $provider = $this->provider();
        $provider->create();
        $key = $provider->load();

        self::assertSame(32, \strlen($key));
        sodium_memzero($key);
        self::assertSame(0o700, fileperms($this->root.'/external/secrets') & 0o777);
        self::assertSame(0o600, fileperms($this->root.'/external/secrets/master.key') & 0o777);
        $metadata = stat($this->root.'/external/secrets/master.key');
        self::assertIsArray($metadata);
        self::assertSame(1, $metadata['nlink']);
    }

    public function testCreateAndRecoveryCrossFileDurabilityBarriersBeforePublication(): void
    {
        $durability = new RecordingFileDurability();
        $provider = $this->provider(fileDurability: $durability);
        $provider->create();
        self::assertSame(1, $durability->calls);

        self::assertTrue(unlink($this->root.'/external/secrets/master.key'));
        $recovered = random_bytes(32);
        try {
            $provider->recover($recovered);
        } finally {
            sodium_memzero($recovered);
        }
        self::assertSame(2, $durability->calls);
    }

    public function testFailedCreateFileBarrierPublishesNoKey(): void
    {
        $provider = $this->provider(fileDurability: new RecordingFileDurability(1));
        try {
            $provider->create();
            self::fail('A failed master-key file durability barrier was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('durability', strtolower($exception->getMessage()));
        }
        self::assertFileDoesNotExist($this->root.'/external/secrets/master.key');
        self::assertSame([], glob($this->root.'/external/secrets/.master-key-*.tmp'));
    }

    public function testFailedRecoveryFileBarrierPublishesNoKey(): void
    {
        $provider = $this->provider(fileDurability: new RecordingFileDurability(1));
        $recovered = random_bytes(32);
        try {
            try {
                $provider->recover($recovered);
                self::fail('A failed recovered-key file durability barrier was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('durability', strtolower($exception->getMessage()));
            }
        } finally {
            sodium_memzero($recovered);
        }
        self::assertFileDoesNotExist($this->root.'/external/secrets/master.key');
        self::assertSame([], glob($this->root.'/external/secrets/.master-key-recovery-*.tmp'));
    }

    public function testOmittedRelativeReleaseAndPublicConfigurationFailClosed(): void
    {
        foreach ([
            new FilesystemMasterKey('', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey('secrets/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey($this->root.'/release/private/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
            new FilesystemMasterKey($this->root.'/release/public/master.key', $this->root.'/release/public', $this->root.'/release', 'prod'),
        ] as $provider) {
            $this->expectCreateFailure($provider);
        }
    }

    public function testExplicitTestPathMayBeIsolatedInsideProjectTree(): void
    {
        $provider = new FilesystemMasterKey(
            $this->root.'/release/var/test-secrets/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'test',
        );
        $provider->create();
        $key = $provider->load();
        self::assertSame(32, \strlen($key));
        sodium_memzero($key);
    }

    public function testReplacementRequiresExpectedKeyAndPublishesRestrictively(): void
    {
        $provider = $this->provider();
        $provider->create();
        $old = $provider->load();
        $replacement = random_bytes(32);

        try {
            $provider->replace($old, $replacement);
            $loaded = $provider->load();
            try {
                self::assertTrue(hash_equals($replacement, $loaded));
            } finally {
                sodium_memzero($loaded);
            }
            self::assertSame(0o600, fileperms($this->root.'/external/secrets/master.key') & 0o777);
            $metadata = stat($this->root.'/external/secrets/master.key');
            self::assertIsArray($metadata);
            self::assertSame(1, $metadata['nlink']);

            $this->expectException(\RuntimeException::class);
            $provider->replace($old, random_bytes(32));
        } finally {
            sodium_memzero($old);
            sodium_memzero($replacement);
        }
    }

    public function testMissingMalformedUnsafeSymlinkHardlinkAndNestedSymlinkEscapeFailClosed(): void
    {
        $this->expectLoadFailure($this->provider());

        self::assertTrue(mkdir($this->root.'/external/secrets', 0o700));
        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', 'malformed'));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o600));
        $this->expectLoadFailure($this->provider());

        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o644));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(unlink($this->root.'/external/secrets/master.key'));
        self::assertTrue(symlink('/dev/null', $this->root.'/external/secrets/master.key'));
        $this->expectLoadFailure($this->provider());
        self::assertTrue(unlink($this->root.'/external/secrets/master.key'));

        self::assertNotFalse(file_put_contents($this->root.'/external/secrets/master.key', sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)));
        self::assertTrue(chmod($this->root.'/external/secrets/master.key', 0o600));
        self::assertTrue(link($this->root.'/external/secrets/master.key', $this->root.'/external/secrets/master.link'));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(mkdir($this->root.'/escape', 0o700));
        self::assertTrue(symlink($this->root.'/escape', $this->root.'/external/nested-link'));
        $escapeProvider = new FilesystemMasterKey(
            $this->root.'/external/nested-link/child/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'prod',
        );
        $this->expectCreateFailure($escapeProvider);
    }

    public function testUnsafeImmediateAndEarlierAncestorDirectoriesFailClosed(): void
    {
        $this->provider()->create();
        self::assertTrue(chmod($this->root.'/external/secrets', 0o755));
        $this->expectLoadFailure($this->provider());

        self::assertTrue(chmod($this->root.'/external/secrets', 0o700));
        self::assertTrue(chmod($this->root.'/external', 0o777));
        $this->expectLoadFailure($this->provider());
    }

    public function testRotationNamespaceMutationsCrossDirectoryDurabilityBarriers(): void
    {
        $durability = new RecordingDirectoryDurability();
        $provider = $this->provider($durability);
        $provider->create();
        $old = $provider->load();
        $replacement = random_bytes(32);
        $operationId = '018f1f6e-7b2c-7a3d-8c4e-0123456789ab';
        try {
            $provider->prepareRotation($old, $replacement, $operationId);
            $provider->publishRotation($operationId);
            $provider->resolveRotation($operationId, false);
            $loaded = $provider->load();
            try {
                self::assertTrue(hash_equals($old, $loaded));
            } finally {
                sodium_memzero($loaded);
            }
        } finally {
            sodium_memzero($old);
            sodium_memzero($replacement);
        }

        self::assertGreaterThanOrEqual(5, $durability->calls);
        self::assertSame(array_fill(0, $durability->calls, $this->root.'/external/secrets'), $durability->directories);
        self::assertFileDoesNotExist($this->root.'/external/secrets/master.key.rotation-journal');
    }

    public function testFailedReplacementDirectoryBarrierRestoresTheDurableOldKey(): void
    {
        $durability = new RecordingDirectoryDurability(2);
        $provider = $this->provider($durability);
        $provider->create();
        $old = $provider->load();
        $replacement = random_bytes(32);
        try {
            try {
                $provider->replace($old, $replacement);
                self::fail('A failed directory durability barrier was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('durability', strtolower($exception->getMessage()));
            }
            $loaded = $provider->load();
            try {
                self::assertTrue(hash_equals($old, $loaded));
            } finally {
                sodium_memzero($loaded);
            }
        } finally {
            sodium_memzero($old);
            sodium_memzero($replacement);
        }
        self::assertSame(3, $durability->calls);
    }

    private function provider(?DirectoryDurability $durability = null, ?FileDurability $fileDurability = null): FilesystemMasterKey
    {
        return new FilesystemMasterKey(
            $this->root.'/external/secrets/master.key',
            $this->root.'/release/public',
            $this->root.'/release',
            'prod',
            $durability,
            $fileDurability,
        );
    }

    private function expectCreateFailure(FilesystemMasterKey $provider): void
    {
        try {
            $provider->create();
            self::fail('Unsafe master-key configuration was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('master', strtolower($exception->getMessage()));
        }
    }

    private function expectLoadFailure(FilesystemMasterKey $provider): void
    {
        try {
            $provider->load();
            self::fail('Unsafe master key was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('master', strtolower($exception->getMessage()));
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            if (file_exists($directory) || is_link($directory)) {
                unlink($directory);
            }

            return;
        }

        $entries = scandir($directory);
        if (false !== $entries) {
            foreach ($entries as $entry) {
                if ('.' !== $entry && '..' !== $entry) {
                    $this->removeDirectory($directory.'/'.$entry);
                }
            }
        }
        rmdir($directory);
    }
}

final class RecordingDirectoryDurability implements DirectoryDurability
{
    public int $calls = 0;

    /** @var list<string> */
    public array $directories = [];

    public function __construct(private readonly ?int $failOnCall = null)
    {
    }

    public function sync(string $directory): void
    {
        ++$this->calls;
        $this->directories[] = $directory;
        if ($this->failOnCall === $this->calls) {
            throw new \RuntimeException('Synthetic directory durability barrier failed.');
        }
    }
}

final class RecordingFileDurability implements FileDurability
{
    public int $calls = 0;

    public function __construct(private readonly ?int $failOnCall = null)
    {
    }

    public function sync(mixed $handle): void
    {
        ++$this->calls;
        if ($this->failOnCall === $this->calls) {
            throw new \RuntimeException('Synthetic file durability barrier failed.');
        }
    }
}
