<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\Clock;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
