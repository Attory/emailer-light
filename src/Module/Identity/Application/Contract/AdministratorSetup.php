<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface AdministratorSetup
{
    public function create(string $loginIdentifier, string $password): string;
}
