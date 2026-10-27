<?php

declare(strict_types=1);

namespace App\Tests\Module\Backup;

use App\Module\Backup\Infrastructure\FilesystemBackupDestination;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class RootBackupCopyTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function copies(): iterable
    {
        yield 'new root-run copy' => [false];
        yield 'interrupted copy exists but not recorded successful' => [true];
    }

    #[DataProvider('copies')]
    public function testCopyIsReadableByItsSourceServiceBeforeAnyHostAdoption(bool $retry): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('The required separate root backup-custody CI gate exercises this case.');
        }
        $base = sys_get_temp_dir().'/emailer-root-copy-'.bin2hex(random_bytes(8));
        mkdir($base, 0o755);
        foreach (['private', 'destination', 'release'] as $name) {
            mkdir($base.'/'.$name, 'release' === $name ? 0o755 : 0o700);
            if ('release' !== $name) {
                chown($base.'/'.$name, 1000);
                chgrp($base.'/'.$name, 1000);
            }
        }
        $id = '01990000-0000-7000-8000-000000000001';
        $name = 'backup-'.$id.'.enxbackup';
        $source = $base.'/private/'.$name;
        $copy = $base.'/destination/'.$name;
        $bytes = str_repeat('Synthetic encrypted recovery bytes.', 8);
        file_put_contents($source, $bytes);
        chmod($source, 0o600);
        chown($source, 1000);
        chgrp($source, 1000);
        if ($retry) {
            file_put_contents($copy, $bytes);
            chmod($copy, 0o600);
        }
        $connection = self::createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn(false);
        $connection->expects(self::exactly(2))->method('executeStatement')->willReturn(1);
        try {
            (new FilesystemBackupDestination($connection, $base.'/destination', $base.'/release', $base.'/private'))->transfer(['id' => $id, 'path' => $source, 'sha256' => hash('sha256', $bytes), 'bytes' => \strlen($bytes)]);
            clearstatcache(true, $copy);
            self::assertSame(1000, fileowner($copy));
            self::assertSame(1000, filegroup($copy));
            self::assertSame(0o600, fileperms($copy) & 0o777);
            $group = posix_getegid();
            self::assertTrue(posix_setegid(1000));
            self::assertTrue(posix_seteuid(1000));
            try {
                self::assertTrue(is_readable($copy));
                self::assertSame(hash('sha256', $bytes), hash_file('sha256', $copy));
            } finally {
                posix_seteuid(0);
                posix_setegid($group);
            }
            self::assertSame($bytes, file_get_contents($source));
        } finally {
            (new Filesystem())->remove($base);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function recordedCopies(): iterable
    {
        foreach (['valid', 'missing', 'tampered', 'public_mode', 'owner_drift', 'symlink', 'hardlink'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('recordedCopies')]
    public function testSuccessfulReceiptIsNotTrustedWithoutRecheckingTheExactObject(string $state): void
    {
        $root = 0 === posix_geteuid();
        if (!$root && 'owner_drift' === $state) {
            self::markTestSkipped('The required root custody gate exercises ownership drift.');
        }
        $base = sys_get_temp_dir().'/emailer-recorded-copy-'.bin2hex(random_bytes(8));
        mkdir($base, 0o755);
        foreach (['private', 'destination', 'release'] as $name) {
            mkdir($base.'/'.$name, 'release' === $name ? 0o755 : 0o700);
            if ($root && 'release' !== $name) {
                chown($base.'/'.$name, 1000);
                chgrp($base.'/'.$name, 1000);
            }
        }
        $id = '01990000-0000-7000-8000-000000000002';
        $name = 'backup-'.$id.'.enxbackup';
        $source = $base.'/private/'.$name;
        $target = $base.'/destination/'.$name;
        $bytes = 'Synthetic verified recovery ciphertext';
        foreach ([$source, $target] as $path) {
            file_put_contents($path, $bytes);
            chmod($path, 0o600);
            if ($root) {
                chown($path, 1000);
                chgrp($path, 1000);
            }
        }
        if ('missing' === $state) {
            unlink($target);
        } elseif ('tampered' === $state) {
            file_put_contents($target, 'Changed synthetic recovery ciphertext');
        } elseif ('public_mode' === $state) {
            chmod($target, 0o644);
        } elseif ('owner_drift' === $state) {
            chown($target, 0);
        } elseif ('symlink' === $state) {
            unlink($target);
            symlink($source, $target);
        } elseif ('hardlink' === $state) {
            link($target, $base.'/other-link');
        }
        $before = @lstat($target);
        $connection = self::createMock(Connection::class);
        $connection->expects(self::once())->method('fetchOne')->willReturn('1');
        $connection->expects('valid' === $state ? self::never() : self::once())->method('executeStatement')->with(self::stringContains("detail_code='verification_failed'"), [$id])->willReturn(1);
        try {
            try {
                (new FilesystemBackupDestination($connection, $base.'/destination', $base.'/release', $base.'/private'))->transfer(['id' => $id, 'path' => $source, 'sha256' => hash('sha256', $bytes), 'bytes' => \strlen($bytes)]);
                self::assertSame('valid', $state);
            } catch (\RuntimeException) {
                self::assertNotSame('valid', $state);
            }
            clearstatcache(true, $target);
            $after = @lstat($target);
            if (false === $before) {
                self::assertFalse($after);
            } else {
                self::assertIsArray($after);
                foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $field) {
                    self::assertSame($before[$field], $after[$field], 'A pre-existing object must retain its custody and content metadata.');
                }
            }
            self::assertSame($bytes, file_get_contents($source));
            if ('tampered' === $state) {
                self::assertSame('Changed synthetic recovery ciphertext', file_get_contents($target));
            }
        } finally {
            (new Filesystem())->remove($base);
        }
    }
}
