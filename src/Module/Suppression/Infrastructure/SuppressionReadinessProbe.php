<?php

declare(strict_types=1);

namespace App\Module\Suppression\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class SuppressionReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe
{
    public function __construct(private Connection $connection)
    {
    }

    public function findings(): array
    {
        try {
            $this->connection->executeQuery('SELECT id FROM suppression LIMIT 1')->fetchOne();

            return [];
        } catch (\Throwable) {
            return [['code' => 'suppression_store', 'severity' => 'critical', 'summary' => 'The global suppression store is unavailable.', 'remediation' => 'Restore database access and suppression integrity before sending.']];
        }
    }
}
