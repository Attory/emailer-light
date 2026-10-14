<?php

declare(strict_types=1);

namespace App\Module\Release\Infrastructure;

use App\Module\Operations\Application\Contract\OperationalStatusProvider;
use Doctrine\DBAL\Connection;

final readonly class OperationalReleaseStatus implements OperationalStatusProvider
{
    public function __construct(private Connection $connection, private string $appVersion)
    {
    }

    public function status(): array
    {
        $last = $this->connection->fetchAssociative('SELECT operation, status, finished_at FROM release_operation ORDER BY finished_at DESC LIMIT 1');

        $status = false === $last || !\is_string($last['status']) ? 'current' : $last['status'];

        return ['key' => 'release', 'title' => 'Release', 'status' => $status, 'metrics' => [
            ['label' => 'Installed version', 'value' => $this->appVersion],
            ['label' => 'Last release operation', 'value' => false === $last || !\is_string($last['operation']) ? 'None' : $last['operation']],
            ['label' => 'Last operation time', 'value' => false === $last || !\is_string($last['finished_at']) ? 'Never' : $last['finished_at']],
        ]];
    }
}
