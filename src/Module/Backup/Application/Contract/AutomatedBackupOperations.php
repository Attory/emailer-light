<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

interface AutomatedBackupOperations
{
    /** @return array{id: string, path: string, sha256: string, bytes: int} */
    public function createAndTransfer(string $destinationType): array;

    public function destinationAvailable(string $destinationType): bool;
}
