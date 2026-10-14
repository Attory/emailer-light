<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\RandomBytes;

final class SecureRandomBytes implements RandomBytes
{
    public function generate(int $length): string
    {
        if ($length < 1) {
            throw new \InvalidArgumentException('Random byte length must be positive.');
        }

        return random_bytes($length);
    }
}
