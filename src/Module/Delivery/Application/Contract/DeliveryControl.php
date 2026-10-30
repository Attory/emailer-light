<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

interface DeliveryControl
{
    /** @return array{configured: bool, owner: string, generation: int, holds: list<array<string, mixed>>} */
    public function status(): array;

    public function pause(): bool;

    public function claimLease(): \App\Shared\DeliveryIntentLease;

    public function enable(): void;
}
