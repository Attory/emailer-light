<?php

declare(strict_types=1);

namespace App\Shared;

/** A bounded, operator-safe explanation of the update's sending restriction. */
final class UpdateSendingBlocked extends \DomainException
{
}
