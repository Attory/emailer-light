<?php

declare(strict_types=1);

namespace App\Module\Backup\Application\Contract;

interface BackupOperations
{
    /** @return array{id: string, path: string, sha256: string, bytes: int} */
    public function create(): array;

    /** @return array<string, mixed> */
    public function verify(string $path): array;

    public function restore(string $path): void;
}
