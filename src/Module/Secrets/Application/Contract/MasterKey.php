<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface MasterKey
{
    public function create(): void;

    public function load(): string;

    public function exists(): bool;

    public function recover(string $key): void;

    public function replace(string $expectedCurrentKey, string $replacementKey): void;
}
