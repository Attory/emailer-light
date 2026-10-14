<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface FileDurability
{
    /** @param resource $handle */
    public function sync(mixed $handle): void;
}
