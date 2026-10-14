<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface DirectoryDurability
{
    public function sync(string $directory): void;
}
