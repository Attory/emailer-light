<?php

declare(strict_types=1);

namespace App\Tests\Module\Operations;

use App\Module\Operations\Application\OperationsPolicy;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class OperationsPolicyTest extends TestCase
{
    public function testBackupAutomationCannotBeEnabledWithoutApprovedDestination(): void
    {
        $policy = new OperationsPolicy(self::createStub(Connection::class));

        $this->expectException(\InvalidArgumentException::class);
        $policy->updateBackup([
            'backup_automation_enabled' => true,
            'backup_destination_type' => 'none',
            'backup_interval_hours' => 24,
            'backup_retention_days' => 30,
            'restore_drill_interval_days' => 90,
        ]);
    }

    public function testRetentionValuesAreBoundedBeforePersistence(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('update');
        $policy = new OperationsPolicy($connection);

        $this->expectException(\InvalidArgumentException::class);
        $policy->updateRetention([
            'retention_automation_enabled' => true,
            'retention_interval_hours' => 0,
            'revoked_session_retention_days' => 30,
            'queue_retention_days' => 7,
            'dead_letter_retention_days' => 90,
            'health_retention_days' => 90,
            'delivery_attempt_retention_days' => 90,
            'log_retention_days' => 30,
        ]);
    }
}
