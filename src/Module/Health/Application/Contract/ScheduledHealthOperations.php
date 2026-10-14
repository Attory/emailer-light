<?php

declare(strict_types=1);

namespace App\Module\Health\Application\Contract;

interface ScheduledHealthOperations
{
    /** @return array<string, mixed> */
    public function runScheduled(): array;
}
