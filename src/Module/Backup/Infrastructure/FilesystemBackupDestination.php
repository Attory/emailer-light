<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Backup\Application\Contract\ExternalBackupDestination;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class FilesystemBackupDestination implements ExternalBackupDestination
{
    public function __construct(
        private Connection $connection,
        private string $destinationDirectory,
        private string $projectDirectory,
        private string $privateDirectory,
    ) {
    }

    public function available(): bool
    {
        try {
            $this->safeRoot();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{id: string, path: string, sha256: string, bytes: int} $backup */
    public function transfer(array $backup): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $backup['id']) || 1 !== preg_match('/^[a-f0-9]{64}$/', $backup['sha256']) || 1 > $backup['bytes']) {
            throw new \InvalidArgumentException('Backup transfer metadata is invalid.');
        }
        $root = $this->safeRoot();
        $name = basename($backup['path']);
        if (1 !== preg_match('/^backup-[0-9a-f-]{36}\.enxbackup$/', $name)) {
            throw new \RuntimeException('Backup object name is invalid.');
        }
        $sourcePath = @lstat($backup['path']);
        $source = @fopen($backup['path'], 'rb');
        if (false === $source) {
            throw new \RuntimeException('Verified backup cannot be opened for transfer.');
        }
        try {
            $owner = fstat($source);
            if (!\is_array($owner) || !\is_array($sourcePath) || !$this->sameObject($owner, $sourcePath) || 0o100000 !== ($owner['mode'] & 0o170000) || 1 !== $owner['nlink'] || $owner['size'] !== $backup['bytes']) {
                throw new \RuntimeException('Verified backup source custody is unavailable.');
            }
            $target = $root.'/'.$name;
            $existing = $this->connection->fetchOne("SELECT 1 FROM backup_transfer WHERE backup_id = ? AND destination_type = 'filesystem' AND status = 'succeeded'", [$backup['id']]);
            if (false !== $existing) {
                try {
                    $verified = $this->verifiedCopy($target, $backup, $owner, true);
                    if (null === $verified) {
                        throw new \RuntimeException('The recorded backup copy is missing, changed or no longer private to its service.');
                    }
                    $this->synchronizeCopy($target, $verified);
                } catch (\Throwable $failure) {
                    $this->connection->executeStatement("UPDATE backup_transfer SET status='failed', detail_code='verification_failed', completed_at=NULL WHERE backup_id=? AND destination_type='filesystem'", [$backup['id']]);
                    throw $failure;
                }

                return;
            }
            $transferId = Identity::uuidV7();
            $attemptedAt = Identity::now();
            $this->connection->executeStatement("INSERT INTO backup_transfer (id, backup_id, destination_type, object_name, ciphertext_sha256, byte_size, status, detail_code, attempted_at) VALUES (?, ?, 'filesystem', ?, ?, ?, 'failed', 'transfer_incomplete', ?) ON DUPLICATE KEY UPDATE ciphertext_sha256 = VALUES(ciphertext_sha256), byte_size = VALUES(byte_size), status = 'failed', detail_code = 'transfer_incomplete', attempted_at = VALUES(attempted_at), completed_at = NULL", [$transferId, $backup['id'], $name, $backup['sha256'], $backup['bytes'], $attemptedAt]);
            $mask = umask(0o077);
            try {
                $destination = @fopen($target, 'x+b');
            } finally {
                umask($mask);
            }
            if (false === $destination) {
                $verified = $this->verifiedCopy($target, $backup, $owner, false);
                if (null === $verified) {
                    throw new \RuntimeException('External backup object already exists or cannot be created.');
                }
                $this->preserveSourceOwner($target, $owner, $verified);
                $this->synchronizeCopy($target, $verified);
                $this->recordSuccess($backup['id']);

                return;
            }
            $created = fstat($destination);
            if (!\is_array($created)) {
                fclose($destination);
                throw new \RuntimeException('New backup copy custody is unavailable.');
            }
            try {
                $copied = stream_copy_to_stream($source, $destination);
                if ($backup['bytes'] !== $copied || !fflush($destination)) {
                    throw new \RuntimeException('External backup transfer was incomplete.');
                }
                $this->preserveSourceOwner($target, $owner, $created);
                if (!fsync($destination)) {
                    throw new \RuntimeException('Backup copy metadata could not be synchronized.');
                }
            } catch (\Throwable $failure) {
                fclose($destination);
                $this->removeCreatedCopy($target, $created);
                throw $failure;
            }
            fclose($destination);
            $verified = $this->verifiedCopy($target, $backup, $owner, true);
            if (null === $verified || !$this->sameObject($created, $verified)) {
                $this->removeCreatedCopy($target, $created);
                throw new \RuntimeException('External backup verification failed.');
            }
            try {
                $this->synchronizeCopy($target, $verified);
            } catch (\Throwable $failure) {
                $this->removeCreatedCopy($target, $created);
                throw $failure;
            }
            $this->recordSuccess($backup['id']);
        } finally {
            fclose($source);
        }
    }

    public function delete(string $objectName): bool
    {
        if (1 !== preg_match('/^backup-[0-9a-f-]{36}\.enxbackup$/', $objectName)) {
            throw new \InvalidArgumentException('Backup object name is invalid.');
        }
        $path = $this->safeRoot().'/'.$objectName;
        if (!file_exists($path)) {
            return false;
        }
        $stat = @lstat($path);
        if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('External backup object is unsafe.');
        }

        return unlink($path);
    }

    private function safeRoot(): string
    {
        if ('' === $this->destinationDirectory || !str_starts_with($this->destinationDirectory, '/')) {
            throw new \RuntimeException('External backup destination is not configured.');
        }
        $root = realpath($this->destinationDirectory);
        $project = realpath($this->projectDirectory);
        $private = realpath($this->privateDirectory);
        $stat = @lstat($this->destinationDirectory);
        if (false === $root || false === $project || !\is_array($stat) || 0o040000 !== ($stat['mode'] & 0o170000) || !is_writable($root)
            || str_starts_with($root.'/', rtrim($project, '/').'/')
            || (false !== $private && str_starts_with($root.'/', rtrim($private, '/').'/'))
        ) {
            throw new \RuntimeException('External backup destination is unsafe.');
        }

        return rtrim($root, '/');
    }

    /** @param array<int|string, int> $a
     * @param array<int|string, int> $b
     */
    private function sameObject(array $a, array $b): bool
    {
        return $a['dev'] === $b['dev'] && $a['ino'] === $b['ino'];
    }

    /** @param array{id: string, path: string, sha256: string, bytes: int} $backup
     * @param array<int|string, int> $owner
     *
     * @return array<int|string, int>|null
     */
    private function verifiedCopy(string $path, array $backup, array $owner, bool $requireOwner): ?array
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!\is_array($before) || 0o100000 !== ($before['mode'] & 0o170000) || 1 !== $before['nlink'] || 0 !== ($before['mode'] & 0o077) || 0 === ($before['mode'] & 0o400) || $before['size'] !== $backup['bytes'] || ($requireOwner && ($before['uid'] !== $owner['uid'] || $before['gid'] !== $owner['gid']))) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }
        try {
            $opened = fstat($handle);
            if (!\is_array($opened) || !$this->sameObject($before, $opened)) {
                return null;
            }
            $hash = hash_init('sha256');
            $read = hash_update_stream($hash, $handle);
            $after = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if ($read !== $backup['bytes'] || !hash_equals($backup['sha256'], hash_final($hash)) || !\is_array($after) || !\is_array($current) || !$this->sameObject($before, $after) || !$this->sameObject($before, $current) || $before['size'] !== $after['size'] || $before['mode'] !== $after['mode'] || $before['uid'] !== $after['uid'] || $before['gid'] !== $after['gid'] || 1 !== $after['nlink']) {
                return null;
            }

            return $after;
        } finally {
            fclose($handle);
        }
    }

    /** @param array<int|string, int> $source
     * @param array<int|string, int> $expected
     */
    private function preserveSourceOwner(string $path, array $source, array $expected): void
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!\is_array($before) || !$this->sameObject($before, $expected) || 0o100000 !== ($before['mode'] & 0o170000) || 1 !== $before['nlink'] || 0 !== ($before['mode'] & 0o077)) {
            throw new \RuntimeException('Backup copy custody changed before ownership assignment.');
        }
        if (0 === posix_geteuid() && (!\in_array($before['uid'], [0, $source['uid']], true) || !lchown($path, $source['uid']) || !lchgrp($path, $source['gid']))) {
            throw new \RuntimeException('Backup copy could not retain its service ownership.');
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!\is_array($after) || !$this->sameObject($expected, $after) || 0o100000 !== ($after['mode'] & 0o170000) || 1 !== $after['nlink'] || 0 !== ($after['mode'] & 0o077) || $source['uid'] !== $after['uid'] || $source['gid'] !== $after['gid']) {
            throw new \RuntimeException('Backup copy ownership changed during transfer.');
        }
    }

    /** @param array<int|string, int> $expected */
    private function synchronizeCopy(string $path, array $expected): void
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Backup copy cannot be synchronized.');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $current = @lstat($path);
            if (!\is_array($opened) || !\is_array($current) || !$this->sameObject($expected, $opened) || !$this->sameObject($expected, $current) || 0o100000 !== ($current['mode'] & 0o170000) || 1 !== $current['nlink'] || 0 !== ($current['mode'] & 0o077) || !fsync($handle)) {
                throw new \RuntimeException('Backup copy metadata could not be synchronized.');
            }
        } finally {
            fclose($handle);
        }
        $directory = @fopen(\dirname($path), 'rb');
        if (false === $directory) {
            throw new \RuntimeException('Backup destination directory cannot be synchronized.');
        }
        try {
            if (!fsync($directory)) {
                throw new \RuntimeException('Backup destination directory could not be synchronized.');
            }
        } finally {
            fclose($directory);
        }
    }

    /** @param array<int|string, int> $created */
    private function removeCreatedCopy(string $path, array $created): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (\is_array($current) && $this->sameObject($created, $current) && 0o100000 === ($current['mode'] & 0o170000) && 1 === $current['nlink']) {
            @unlink($path);
        }
    }

    private function recordSuccess(string $backupId): void
    {
        $this->connection->executeStatement("UPDATE backup_transfer SET status = 'succeeded', detail_code = 'verified_copy', completed_at = ? WHERE backup_id = ? AND destination_type = 'filesystem'", [Identity::now(), $backupId]);
    }
}
