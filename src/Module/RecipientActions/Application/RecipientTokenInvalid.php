<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Application;

final class RecipientTokenInvalid extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Recipient action is unavailable.');
    }
}
