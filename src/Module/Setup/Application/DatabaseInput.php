<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final readonly class DatabaseInput
{
    public function __construct(
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        #[\SensitiveParameter]
        public string $password,
    ) {
        if ('' === trim($host) || $port < 1 || $port > 65_535 || '' === trim($database) || '' === trim($username) || '' === $password) {
            throw new \InvalidArgumentException('Database configuration is incomplete.');
        }
    }
}
