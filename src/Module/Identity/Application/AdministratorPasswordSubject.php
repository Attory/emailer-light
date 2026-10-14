<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;

final class AdministratorPasswordSubject implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
