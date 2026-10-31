<?php

declare(strict_types=1);

namespace App\Shared;

/** Safe sending diagnostic; no protected record content or exception chain. */
final class DeliveryControlUnavailable extends \DomainException
{
    public function __construct()
    {
        parent::__construct('delivery_control_unavailable: delivery controls are unreadable or invalid; sending is blocked. Use the verified installer repair and preserve owner pauses and operation holds.');
    }
}
