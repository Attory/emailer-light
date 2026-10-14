<?php

declare(strict_types=1);

namespace App\Module\Operations\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class OperationsBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['operations_policy', 'operations_run'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
