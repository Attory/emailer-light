<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class SecretsBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['encrypted_secret', 'recipient_token_key_state', 'recipient_token_key'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
