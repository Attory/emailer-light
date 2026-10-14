<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class AuditBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['audit_event'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
