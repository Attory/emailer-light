<?php

declare(strict_types=1);

namespace App\Module\Setup\Domain;

final readonly class PreflightCheck
{
    public function __construct(
        public string $name,
        public bool $passed,
        public string $message,
    ) {
    }
}
