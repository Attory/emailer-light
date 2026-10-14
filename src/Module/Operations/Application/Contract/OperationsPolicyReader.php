<?php

declare(strict_types=1);

namespace App\Module\Operations\Application\Contract;

interface OperationsPolicyReader
{
    /** @return array<string, int|string> */
    public function current(): array;
}
