<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

use App\Module\Setup\Application\SetupProgress;

interface SetupState
{
    public function progress(): SetupProgress;

    public function initializeKey(): string;

    public function mark(string $step): void;
}
