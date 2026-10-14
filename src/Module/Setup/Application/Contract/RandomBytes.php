<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface RandomBytes
{
    public function generate(int $length): string;
}
