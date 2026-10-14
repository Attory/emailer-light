<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface InstallationLock
{
    public function exists(): bool;

    /** @param array<string, bool|int|string> $metadata */
    public function create(array $metadata): void;

    public function remove(): void;
}
