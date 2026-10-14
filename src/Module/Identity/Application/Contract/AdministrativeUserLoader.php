<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

use App\Module\Identity\Application\AuthenticatedUser;

interface AdministrativeUserLoader
{
    public function loadForAuthentication(string $identifier): AuthenticatedUser;
}
