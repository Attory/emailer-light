<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

interface ExternalBackupDestination
{
    public function available(): bool;

    /** @param array{id: string, path: string, sha256: string, bytes: int} $backup */
    public function transfer(array $backup): void;

    public function delete(string $objectName): bool;
}
