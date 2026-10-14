<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

final readonly class SensitiveValueHasher
{
    public function __construct(private string $key)
    {
        if (16 > \strlen($this->key)) {
            throw new \InvalidArgumentException('Sensitive-value hashing key is unavailable.');
        }
    }

    public function hash(string $purpose, string $value): string
    {
        return hash_hmac('sha256', $purpose."\0".$value, $this->key);
    }
}
