<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\DirectoryDurability;

final readonly class PosixDirectoryDurability implements DirectoryDurability
{
    public function sync(string $directory): void
    {
        if (!\function_exists('fsync')) {
            throw new \RuntimeException('Directory durability is unavailable on this runtime.');
        }

        $handle = @fopen($directory, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Filesystem directory cannot be opened for a durability barrier.');
        }

        try {
            $metadata = fstat($handle);
            if (false === $metadata || 0o040000 !== ($metadata['mode'] & 0o170000) || !fsync($handle)) {
                throw new \RuntimeException('Filesystem directory durability barrier failed.');
            }
        } finally {
            fclose($handle);
        }
    }
}
