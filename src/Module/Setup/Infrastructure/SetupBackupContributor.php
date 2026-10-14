<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class SetupBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['product_identity', 'doctrine_migration_versions', 'installation_state', 'setup_installation_event', 'organisation_settings', 'delivery_policy', 'privacy_policy', 'sender_identity'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
