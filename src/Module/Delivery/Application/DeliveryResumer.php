<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Delivery\Application\Contract\SendingGate;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final readonly class DeliveryResumer
{
    public function __construct(private Connection $connection, private SendingGate $sendingGate, private MaintenanceOperationLock $maintenance)
    {
    }

    public function resume(): void
    {
        $this->maintenance->acquire();
        try {
            $this->sendingGate->assertMaintenanceReady();
            if (1 !== $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1' AND schema_version = 1 AND accepting_work = 0")) {
                throw new \RuntimeException('Delivery runtime was not quiesced or compatible.');
            }
        } finally {
            $this->maintenance->release();
        }
    }
}
