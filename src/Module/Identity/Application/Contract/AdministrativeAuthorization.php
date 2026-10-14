<?php

declare(strict_types=1);

namespace App\Module\Identity\Application\Contract;

interface AdministrativeAuthorization
{
    public function assertAtLeastRank(AdministrativeActor $actor, AdministrativeAccessLevel $required, string $action, ?string $sourceHash = null): void;
}
