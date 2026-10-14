<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;
use Doctrine\DBAL\Connection;

final readonly class OperationalAuditStatus implements OperationalStatusProvider
{
    public function __construct(private Connection $connection)
    {
    }

    public function status(): array
    {
        $countValue = $this->connection->fetchOne('SELECT COUNT(*) FROM audit_event');
        $count = is_numeric($countValue) ? (int) $countValue : 0;
        $last = $this->connection->fetchAssociative('SELECT action, result, occurred_at FROM audit_event ORDER BY occurred_at DESC LIMIT 1');

        return ['key' => 'audit', 'title' => 'Audit evidence', 'status' => 'available', 'metrics' => [
            ['label' => 'Recorded events', 'value' => $count],
            ['label' => 'Latest action', 'value' => false === $last || !\is_string($last['action']) ? 'None' : $last['action']],
            ['label' => 'Latest result', 'value' => false === $last || !\is_string($last['result']) ? 'None' : $last['result']],
            ['label' => 'Latest event time', 'value' => false === $last || !\is_string($last['occurred_at']) ? 'Never' : $last['occurred_at']],
        ]];
    }
}
