<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface CliSetupState
{
    public function recordOwnershipVerified(int $verifiedAt): void;

    public function delete(): void;
}
