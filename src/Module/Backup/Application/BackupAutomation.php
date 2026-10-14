<?php

declare(strict_types=1);

namespace App\Module\Backup\Application;

use App\Module\Backup\Application\Contract\AutomatedBackupOperations;
use App\Module\Backup\Application\Contract\BackupRetentionOperations;
use App\Module\Backup\Application\Contract\ExternalBackupDestination;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class BackupAutomation implements AutomatedBackupOperations, BackupRetentionOperations
{
    private const BATCH = 200;

    public function __construct(private BackupEngine $backups, private ExternalBackupDestination $destination, private Connection $connection, private string $backupDirectory)
    {
    }

    public function createAndTransfer(string $destinationType): array
    {
        if ('filesystem' !== $destinationType || !$this->destination->available()) {
            throw new \RuntimeException('Approved external backup destination is unavailable.');
        }
        $backup = $this->backups->create();
        $this->destination->transfer($backup);

        return $backup;
    }

    public function destinationAvailable(string $destinationType): bool
    {
        return 'filesystem' === $destinationType && $this->destination->available();
    }

    public function prune(bool $dryRun, int $retentionDays): int
    {
        if (2 > $retentionDays || 365 < $retentionDays) {
            throw new \InvalidArgumentException('Backup retention is outside safe bounds.');
        }
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.$retentionDays.' days')->format('Y-m-d H:i:s.u');
        $rows = $this->connection->fetchAllAssociative("SELECT id, filename FROM backup_record WHERE deleted_at IS NULL AND created_at < ? AND id <> COALESCE((SELECT latest.id FROM (SELECT id FROM backup_record WHERE status = 'verified' AND deleted_at IS NULL ORDER BY verified_at DESC LIMIT 1) latest), '') ORDER BY created_at LIMIT ".self::BATCH, [$cutoff]);
        if ($dryRun) {
            return \count($rows);
        }
        $deleted = 0;
        foreach ($rows as $row) {
            if (!\is_string($row['id'] ?? null) || !\is_string($row['filename'] ?? null) || 1 !== preg_match('/^backup-[0-9a-f-]{36}\.enxbackup$/', $row['filename'])) {
                throw new \RuntimeException('Backup retention evidence is invalid.');
            }
            $local = $this->safeLocalPath($row['filename']);
            if (null !== $local && !unlink($local)) {
                throw new \RuntimeException('Expired local backup could not be deleted.');
            }
            $transfer = $this->connection->fetchAssociative("SELECT object_name FROM backup_transfer WHERE backup_id = ? AND destination_type = 'filesystem' AND status = 'succeeded'", [$row['id']]);
            if (false !== $transfer && \is_string($transfer['object_name'])) {
                $this->destination->delete($transfer['object_name']);
                $this->connection->executeStatement("UPDATE backup_transfer SET status = 'deleted', detail_code = 'retention_deleted', completed_at = ? WHERE backup_id = ? AND destination_type = 'filesystem'", [Identity::now(), $row['id']]);
            }
            $this->connection->update('backup_record', ['status' => 'deleted', 'deleted_at' => Identity::now()], ['id' => $row['id']]);
            ++$deleted;
        }

        return $deleted;
    }

    private function safeLocalPath(string $filename): ?string
    {
        $root = realpath($this->backupDirectory);
        $path = $this->backupDirectory.'/'.$filename;
        if (!file_exists($path)) {
            return null;
        }
        $real = realpath($path);
        $stat = @lstat($path);
        if (false === $root || false === $real || !str_starts_with($real.'/', rtrim($root, '/').'/') || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('Expired backup path is unsafe.');
        }

        return $real;
    }
}
