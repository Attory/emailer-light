<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface SecretVault
{
    public function store(string $recordType, string $plaintext, string $installationId): string;

    public function replace(string $recordId, string $recordType, string $plaintext, string $installationId): void;

    public function reveal(string $recordId, string $recordType, string $installationId): string;
}
