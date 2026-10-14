<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup;

use App\Module\Backup\Infrastructure\FilesystemBackupDestination;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class FilesystemBackupDestinationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/emailer-backup-destination-'.bin2hex(random_bytes(8));
        mkdir($this->root, 0o700);
        mkdir($this->root.'/project', 0o700);
        mkdir($this->root.'/private', 0o700);
        mkdir($this->root.'/external', 0o700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->root.'/*/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        foreach (['external', 'private', 'project'] as $directory) {
            rmdir($this->root.'/'.$directory);
        }
        rmdir($this->root);
    }

    public function testTransferUsesExclusiveRestrictiveVerifiedCopyWithoutPersistingPath(): void
    {
        $id = '018f5f4c-6e35-7d55-b8db-9bdfefbdaf10';
        $source = $this->root.'/private/backup-'.$id.'.enxbackup';
        file_put_contents($source, 'encrypted-test-backup');
        chmod($source, 0o600);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn(false);
        $connection->expects(self::exactly(2))->method('executeStatement')->with(self::callback(static fn (string $sql): bool => !str_contains($sql, '/')),
            self::anything());
        $destination = new FilesystemBackupDestination($connection, $this->root.'/external', $this->root.'/project', $this->root.'/private');

        $hash = hash_file('sha256', $source);
        $bytes = filesize($source);
        if (false === $hash || false === $bytes) {
            self::fail('Synthetic encrypted backup metadata is unavailable.');
        }
        $destination->transfer(['id' => $id, 'path' => $source, 'sha256' => $hash, 'bytes' => $bytes]);

        $copy = $this->root.'/external/'.basename($source);
        self::assertFileExists($copy);
        self::assertSame(hash_file('sha256', $source), hash_file('sha256', $copy));
        self::assertSame(0o600, fileperms($copy) & 0o777);
    }
}
