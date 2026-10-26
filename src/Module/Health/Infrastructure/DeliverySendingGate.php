<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Health\Application\Doctor;

final readonly class DeliverySendingGate implements SendingGate
{
    public function __construct(private Doctor $doctor, private ?\App\Shared\UpdateDeliveryState $updateState = null)
    {
    }

    public function assertReady(): void
    {
        $this->updateState?->assertSendingAllowed();
        $this->doctor->assertSendingReady();
    }

    public function assertMaintenanceReady(): void
    {
        if (0 < $this->doctor->run(false, true)['critical_count']) {
            throw new \DomainException('Delivery resume is blocked by critical readiness findings.');
        }
    }
}
