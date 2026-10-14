<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application\Contract;

interface RecoveryKeyVerifier
{
    public function verifiesKey(string $passphrase, string $expectedKey): bool;
}
