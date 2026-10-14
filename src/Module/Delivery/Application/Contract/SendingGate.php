<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

interface SendingGate
{
    public function assertReady(): void;

    public function assertMaintenanceReady(): void;
}
