<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

/** A supervised process may wait for this gate; it must never bypass it. */
final class SendingNotReady extends \DomainException
{
}
