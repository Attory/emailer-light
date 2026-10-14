<?php

declare(strict_types=1);

namespace App\Module\Operations\Application\Contract;

interface OperationalStatusProvider
{
    /** @return array{key: string, title: string, status: string, metrics: list<array{label: string, value: int|string}>} */
    public function status(): array;
}
