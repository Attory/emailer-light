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
        $existing = $this->connection->fetchOne("SELECT 1 FROM backup_transfer WHERE backup_id = ? AND destination_type = 'filesystem' AND status = 'succeeded'", [$backup['id']]);
        if (false !== $existing) {
            return;
        }

        $transferId = Identity::uuidV7();
        $attemptedAt = Identity::now();
        $this->connection->executeStatement("INSERT INTO backup_transfer (id, backup_id, destination_type, object_name, ciphertext_sha256, byte_size, status, detail_code, attempted_at) VALUES (?, ?, 'filesystem', ?, ?, ?, 'failed', 'transfer_incomplete', ?) ON DUPLICATE KEY UPDATE ciphertext_sha256 = VALUES(ciphertext_sha256), byte_size = VALUES(byte_size), status = 'failed', detail_code = 'transfer_incomplete', attempted_at = VALUES(attempted_at), completed_at = NULL", [$transferId, $backup['id'], $name, $backup['sha256'], $backup['bytes'], $attemptedAt]);

        $target = $root.'/'.$name;
        $source = fopen($backup['path'], 'rb');
        if (false === $source) {
            throw new \RuntimeException('Verified backup cannot be opened for transfer.');
        }
        $destination = @fopen($target, 'x+b');
        if (false === $destination) {
            fclose($source);
            if ($this->existingCopyMatches($target, $backup['sha256'], $backup['bytes'])) {
                $this->recordSuccess($backup['id']);

                return;
            }
            throw new \RuntimeException('External backup object already exists or cannot be created.');
        }
        try {
            @chmod($target, 0o600);
            $copied = stream_copy_to_stream($source, $destination);
            if (false === $copied || $backup['bytes'] !== $copied || !fflush($destination) || (\function_exists('fsync') && !fsync($destination))) {
                throw new \RuntimeException('External backup transfer was incomplete.');
            }
        } catch (\Throwable $exception) {
            fclose($destination);
            fclose($source);
            @unlink($target);
            throw $exception;
        }
        fclose($destination);
        fclose($source);
        $hash = hash_file('sha256', $target);
        $stat = @lstat($target);
        if (!\is_string($hash) || !hash_equals($backup['sha256'], $hash) || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 0 !== ($stat['mode'] & 0o077)) {
            @unlink($target);
            throw new \RuntimeException('External backup verification failed.');
        }
        $this->recordSuccess($backup['id']);
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

    private function existingCopyMatches(string $path, string $expectedHash, int $expectedBytes): bool
    {
        $stat = @lstat($path);
        if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077)) {
            return false;
        }
        $hash = hash_file('sha256', $path);

        return \is_string($hash) && $expectedBytes === $stat['size'] && hash_equals($expectedHash, $hash);
    }

    private function recordSuccess(string $backupId): void
    {
        $this->connection->executeStatement("UPDATE backup_transfer SET status = 'succeeded', detail_code = 'verified_copy', completed_at = ? WHERE backup_id = ? AND destination_type = 'filesystem'", [Identity::now(), $backupId]);
    }
}
