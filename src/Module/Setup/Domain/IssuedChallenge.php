<?php

declare(strict_types=1);

namespace App\Module\Setup\Domain;

final readonly class IssuedChallenge
{
    public function __construct(
        public string $identifier,
        public string $displayCode,
        public int $expiresAt,
    ) {
    }
}
