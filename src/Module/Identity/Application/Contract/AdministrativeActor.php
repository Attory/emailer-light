<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface AdministrativeActor
{
    public function id(): string;

    public function roleRank(): int;

    public function active(): bool;

    public function loginIdentifier(): string;
}
