<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

interface DeliveryTransport
{
    /** Returns an opaque, non-secret provider reference suitable only for hashing. */
    public function send(OutboundMessage $message): string;
}
