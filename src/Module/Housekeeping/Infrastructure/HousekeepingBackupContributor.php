<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class HousekeepingBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['housekeeping_run'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
