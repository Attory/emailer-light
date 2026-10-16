<?php

declare(strict_types=1);

namespace App\Module\Health\Application\Contract;

interface RuntimeVerification
{
    /** @return array<string, mixed> */
    public function verifyRuntime(bool $offlineMaintenance = false): array;
}
