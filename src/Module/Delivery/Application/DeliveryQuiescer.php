<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final readonly class DeliveryQuiescer
{
    public function __construct(private Connection $connection, private InterruptedDeliveryRecovery $recovery, private MaintenanceOperationLock $maintenance)
    {
    }

    public function quiesce(): int
    {
        $this->maintenance->acquire();
        try {
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
            $this->recovery->recover();
            $active = $this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key = '1'");
            if (!is_numeric($active)) {
                throw new \UnexpectedValueException('Delivery runtime count is invalid.');
            }

            return (int) $active;
        } finally {
            $this->maintenance->release();
        }
    }
}
