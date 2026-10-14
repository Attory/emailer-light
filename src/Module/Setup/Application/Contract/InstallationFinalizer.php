<?php

declare(strict_types=1);

namespace App\Module\Setup\Application\Contract;

interface InstallationFinalizer
{
    public function finalize(): string;
}
