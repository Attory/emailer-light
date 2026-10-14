<?php

declare(strict_types=1);

namespace App\Module\Analytics\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class AnalyticsBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['analytics_aggregate', 'rsvp_response'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
