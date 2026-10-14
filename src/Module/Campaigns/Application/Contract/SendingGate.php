<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Application\Contract;

interface SendingGate
{
    public function assertReady(): void;
}
