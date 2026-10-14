<?php

declare(strict_types=1);

namespace App\Module\Templates\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class TemplatesBackupContributor implements BackupContributor
{
    public function __construct(private string $assetDirectory)
    {
    }

    public function tables(): array
    {
        return ['email_template', 'template_version', 'template_asset'];
    }

    public function assetRoots(): array
    {
        return ['template-assets' => $this->assetDirectory];
    }
}
