<?php

declare(strict_types=1);

namespace App\Module\Release\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class ReleaseBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['release_operation'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
