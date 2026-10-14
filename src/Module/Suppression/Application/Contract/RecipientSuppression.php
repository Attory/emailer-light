<?php

declare(strict_types=1);

namespace App\Module\Suppression\Application\Contract;

interface RecipientSuppression
{
    public function suppressFromRecipientAction(string $email): string;
}
