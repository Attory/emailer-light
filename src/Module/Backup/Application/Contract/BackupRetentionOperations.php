<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

interface BackupRetentionOperations
{
    public function prune(bool $dryRun, int $retentionDays): int;
}
