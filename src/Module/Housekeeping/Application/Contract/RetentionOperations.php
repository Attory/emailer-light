<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Application\Contract;

interface RetentionOperations
{
    /** @return array<string, int> */
    public function run(bool $dryRun): array;
}
