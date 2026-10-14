<?php

declare(strict_types=1);

namespace App\Module\Suppression\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class SuppressionBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['suppression'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
