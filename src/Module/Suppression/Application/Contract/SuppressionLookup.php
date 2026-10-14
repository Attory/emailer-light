<?php

declare(strict_types=1);

namespace App\Module\Suppression\Application\Contract;

interface SuppressionLookup
{
    public function isSuppressed(string $canonicalEmail): bool;
}
