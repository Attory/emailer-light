<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

enum AdministrativeRole: string
{
    case Administrator = 'ROLE_ADMIN';

    public function label(): string
    {
        return 'Administrator';
    }

    public function rank(): int
    {
        return 30;
    }
}
