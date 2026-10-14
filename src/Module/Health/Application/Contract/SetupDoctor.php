<?php

declare(strict_types=1);

namespace App\Module\Health\Application\Contract;

interface SetupDoctor
{
    /** @return list<string> */
    public function criticalFailures(): array;
}
