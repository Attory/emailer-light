<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use App\Module\Secrets\Application\RecoveryKeyExporter;
use App\Module\Secrets\Infrastructure\FilesystemMasterKey;
use PHPUnit\Framework\TestCase;

final class RecoveryKeyExporterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-recovery-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        foreach (['master.key', 'recovery.enxkey'] as $file) {
            @unlink($this->directory.'/'.$file);
        }
        @rmdir($this->directory);
    }

    public function testEncryptedRecoveryPackageRestoresOnlyAMissingMasterKey(): void
    {
        $master = new FilesystemMasterKey($this->directory.'/master.key', $this->directory.'/public', '/application', 'prod');
        $master->create();
        $expected = $master->load();
        $recovery = new RecoveryKeyExporter($master, $this->directory.'/recovery.enxkey');
        $recovery->export('synthetic recovery passphrase');
        self::assertSame(0o600, fileperms($this->directory.'/recovery.enxkey') & 0o777);
        unlink($this->directory.'/master.key');
        $recovery->recover('synthetic recovery passphrase');
        $actual = $master->load();
        self::assertSame($expected, $actual);
        sodium_memzero($expected);
        sodium_memzero($actual);

        $this->expectException(\RuntimeException::class);
        $recovery->recover('synthetic recovery passphrase');
    }

    public function testWrongPassphraseDoesNotCreateAKey(): void
    {
        $master = new FilesystemMasterKey($this->directory.'/master.key', $this->directory.'/public', '/application', 'prod');
        $master->create();
        $recovery = new RecoveryKeyExporter($master, $this->directory.'/recovery.enxkey');
        $recovery->export('synthetic recovery passphrase');
        unlink($this->directory.'/master.key');
        try {
            $recovery->recover('wrong synthetic passphrase');
            self::fail('Wrong recovery passphrase was accepted.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->directory.'/master.key');
        }
    }

    public function testCurrentKeyVerificationAuthenticatesPackageAndPassphrase(): void
    {
        $master = new FilesystemMasterKey($this->directory.'/master.key', $this->directory.'/public', '/application', 'prod');
        $master->create();
        $recovery = new RecoveryKeyExporter($master, $this->directory.'/recovery.enxkey');
        $recovery->export('synthetic recovery passphrase');

        $current = $master->load();
        try {
            self::assertTrue($recovery->verifiesKey('synthetic recovery passphrase', $current));
            self::assertFalse($recovery->verifiesKey('wrong synthetic passphrase', $current));
            self::assertFalse($recovery->verifiesKey('synthetic recovery passphrase', 'malformed'));
            $replacement = random_bytes(32);
            $master->replace($current, $replacement);
            self::assertFalse($recovery->verifiesKey('synthetic recovery passphrase', $replacement));
        } finally {
            sodium_memzero($current);
            if (isset($replacement)) {
                sodium_memzero($replacement);
            }
        }
    }

    public function testExportNeverOverwritesAnExistingRecoveryPackage(): void
    {
        $master = new FilesystemMasterKey($this->directory.'/master.key', $this->directory.'/public', '/application', 'prod');
        $master->create();
        $path = $this->directory.'/recovery.enxkey';
        file_put_contents($path, 'existing');
        chmod($path, 0o600);

        $this->expectException(\RuntimeException::class);
        (new RecoveryKeyExporter($master, $path))->export('synthetic recovery passphrase');
    }
}
