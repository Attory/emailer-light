<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Delivery\Application\Contract\SendingGate;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final readonly class DeliveryResumer
{
    public function __construct(private Connection $connection, private SendingGate $sendingGate, private MaintenanceOperationLock $maintenance, private ?\App\Shared\UpdateDeliveryState $updateState = null, private ?Contract\DeliveryControl $control = null)
    {
    }

    public function resume(): void
    {
        $this->updateState?->assertResumeAllowed();
        $this->maintenance->acquire();
        try {
            $this->sendingGate->assertMaintenanceReady();
            $independent = $this->control?->status();
            $recordedPause = null !== $independent && $independent['configured'] && 'enabled' !== $independent['owner'];
            $this->control?->enable();
            $this->connection->transactional(function () use ($recordedPause): void {
                $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work FROM delivery_runtime WHERE singleton_key = '1' FOR UPDATE");
                if (false === $runtime || !is_numeric($runtime['schema_version']) || !is_numeric($runtime['accepting_work']) || 1 !== (int) $runtime['schema_version'] || (0 !== (int) $runtime['accepting_work'] && !(1 === (int) $runtime['accepting_work'] && $recordedPause))) {
                    throw new \RuntimeException('Delivery runtime was not quiesced or compatible.');
                }
                $this->updateState?->assertResumeAllowed();
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 1, updated_at = UTC_TIMESTAMP(6) WHERE singleton_key = '1'");
            });
        } finally {
            $this->maintenance->release();
        }
    }
}
