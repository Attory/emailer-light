<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\FileDurability;

final readonly class PosixFileDurability implements FileDurability
{
    public function sync(mixed $handle): void
    {
        if (!\is_resource($handle) || !\function_exists('fsync') || !fsync($handle)) {
            throw new \RuntimeException('Filesystem file durability barrier failed.');
        }
    }
}
