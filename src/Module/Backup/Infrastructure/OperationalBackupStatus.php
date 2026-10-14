<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;
use Doctrine\DBAL\Connection;

final readonly class OperationalBackupStatus implements OperationalStatusProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function status(): array
    {
        $last = $this->connection->fetchAssociative("SELECT verified_at, restored_at FROM backup_record WHERE status = 'verified' ORDER BY verified_at DESC LIMIT 1");
        $lastRestore = $this->connection->fetchOne('SELECT MAX(restored_at) FROM backup_record');
        $transfer = $this->connection->fetchAssociative('SELECT status, completed_at FROM backup_transfer ORDER BY attempted_at DESC LIMIT 1');

        return ['key' => 'backup', 'title' => 'Backup and recovery', 'status' => false === $last ? 'missing' : (false !== $transfer && 'failed' === $transfer['status'] ? 'attention' : 'verified'), 'metrics' => [
            ['label' => 'Last verified backup', 'value' => false === $last || !\is_string($last['verified_at']) ? 'Never' : $last['verified_at']],
            ['label' => 'Last restore evidence', 'value' => \is_string($lastRestore) ? $lastRestore : 'Never'],
            ['label' => 'External transfer', 'value' => false === $transfer || !\is_string($transfer['status']) ? 'Not run' : $transfer['status']],
        ]];
    }
}
