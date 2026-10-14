<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class IdentityReadinessProbe implements LightweightReadinessProbe
{
    public function __construct(private Connection $connection, private string $environment)
    {
    }

    public function findings(): array
    {
        if ('test' === $this->environment) {
            return [];
        }
        $count = $this->connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE singleton_key = '1' AND active = 1");

        return 1 === (int) (is_numeric($count) ? $count : 0) ? [] : [[
            'code' => 'administrator_uniqueness',
            'severity' => 'critical',
            'summary' => 'Exactly one active administrator is required.',
            'remediation' => 'Use the protected CLI recovery command or restore a verified backup.',
        ]];
    }
}
