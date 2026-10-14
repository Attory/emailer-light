<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

interface InterruptedDeliveryRecovery
{
    public function recover(): void;
}
