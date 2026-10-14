<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class HealthBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['health_check_run', 'health_finding_state'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
