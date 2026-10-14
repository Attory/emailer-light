<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Campaigns\Application\Contract\SendingGate;
use App\Module\Health\Application\Doctor;

final readonly class CampaignSendingGate implements SendingGate
{
    public function __construct(private Doctor $doctor)
    {
    }

    public function assertReady(): void
    {
        $this->doctor->assertSendingReady();
    }
}
