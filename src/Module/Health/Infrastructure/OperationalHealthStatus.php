<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;
use Doctrine\DBAL\Connection;

final readonly class OperationalHealthStatus implements OperationalStatusProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function status(): array
    {
        $criticalValue = $this->connection->fetchOne("SELECT COUNT(*) FROM health_finding_state WHERE active = 1 AND severity = 'critical'");
        $warningValue = $this->connection->fetchOne("SELECT COUNT(*) FROM health_finding_state WHERE active = 1 AND severity = 'warning'");
        $critical = is_numeric($criticalValue) ? (int) $criticalValue : 0;
        $warning = is_numeric($warningValue) ? (int) $warningValue : 0;
        $last = $this->connection->fetchAssociative("SELECT status, finished_at FROM health_check_run WHERE run_type = 'full' ORDER BY finished_at DESC LIMIT 1");

        $status = false === $last || !\is_string($last['status']) ? 'not_run' : $last['status'];

        return ['key' => 'health', 'title' => 'Configuration Doctor', 'status' => 0 < $critical ? 'critical' : (0 < $warning ? 'warning' : $status), 'metrics' => [
            ['label' => 'Active critical findings', 'value' => $critical],
            ['label' => 'Active warnings', 'value' => $warning],
            ['label' => 'Last full run', 'value' => false === $last || !\is_string($last['finished_at']) ? 'Never' : $last['finished_at']],
        ]];
    }
}
