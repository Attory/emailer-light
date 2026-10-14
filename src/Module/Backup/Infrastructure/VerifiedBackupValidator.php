<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Backup\Application\BackupEngine;
use App\Module\Setup\Application\Contract\BackupValidator;

final readonly class VerifiedBackupValidator implements BackupValidator
{
    public function __construct(private BackupEngine $backups)
    {
    }

    public function isValid(string $backupPath, int $now): bool
    {
        return $this->backups->isRecentVerified($backupPath, $now);
    }
}
