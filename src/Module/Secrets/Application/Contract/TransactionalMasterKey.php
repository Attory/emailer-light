<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface TransactionalMasterKey extends MasterKey
{
    public function beginRotation(string $expectedCurrentKey, string $replacementKey, string $operationId): void;

    public function publishRotation(string $operationId): void;

    public function resolveRotation(string $operationId): void;
}
