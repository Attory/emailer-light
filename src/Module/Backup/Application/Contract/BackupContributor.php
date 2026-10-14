<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

interface BackupContributor
{
    /** @return list<string> */
    public function tables(): array;

    /** @return array<string, string> Logical name to absolute private/source directory. */
    public function assetRoots(): array;
}
