<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

use App\Module\Setup\Domain\PreflightCheck;

interface Preflight
{
    /** @return list<PreflightCheck> */
    public function run(?string $documentRoot = null): array;
}
