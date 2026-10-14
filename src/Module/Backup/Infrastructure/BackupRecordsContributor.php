<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class BackupRecordsContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['backup_record', 'backup_transfer'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
