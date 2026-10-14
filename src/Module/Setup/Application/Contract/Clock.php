<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface Clock
{
    public function now(): int;
}
