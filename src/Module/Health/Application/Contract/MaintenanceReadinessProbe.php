<?php

declare(strict_types=1);

namespace App\Module\Health\Application\Contract;

/** Expected business conditions during offline verification; never permission to send. */
interface MaintenanceReadinessProbe
{
    public function offlineVerificationReady(): bool;

    /** @return list<string> */
    public function maintenanceFindings(): array;
}
