<?php

declare(strict_types=1);

namespace App\Module\Operations\Application\Contract;

interface OperationsPolicyWriter
{
    /** @param array<string, mixed> $values */
    public function updateRetention(array $values): void;

    /** @param array<string, mixed> $values */
    public function updateBackup(array $values): void;
}
