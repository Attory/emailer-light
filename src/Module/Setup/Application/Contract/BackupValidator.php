<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface BackupValidator
{
    public function isValid(string $backupPath, int $now): bool;
}
