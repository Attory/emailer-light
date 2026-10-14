<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

interface RecipientRuntimeConfiguration
{
    /** @return array{installation_id: string, canonical_origin: string} */
    public function current(): array;
}
