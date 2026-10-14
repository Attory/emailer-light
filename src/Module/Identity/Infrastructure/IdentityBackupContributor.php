<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class IdentityBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['app_user', 'user_session', 'login_throttle'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
