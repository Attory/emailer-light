<?php

declare(strict_types=1);

namespace App\Module\Operations\Application;

use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Module\Operations\Application\Contract\OperationsPolicyWriter;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class OperationsPolicy implements OperationsPolicyReader, OperationsPolicyWriter
{
    private const RETENTION_BOUNDS = [
        'retention_interval_hours' => [1, 168],
        'revoked_session_retention_days' => [7, 365],
        'queue_retention_days' => [1, 90],
        'dead_letter_retention_days' => [30, 730],
        'health_retention_days' => [30, 730],
        'delivery_attempt_retention_days' => [30, 730],
        'log_retention_days' => [1, 365],
    ];
    private const BACKUP_BOUNDS = [
        'backup_interval_hours' => [1, 720],
        'backup_retention_days' => [2, 365],
        'restore_drill_interval_days' => [7, 365],
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function current(): array
    {
        $row = $this->connection->fetchAssociative("SELECT doctor_interval_hours, retention_automation_enabled, retention_interval_hours, revoked_session_retention_days, queue_retention_days, dead_letter_retention_days, health_retention_days, delivery_attempt_retention_days, log_retention_days, backup_automation_enabled, backup_interval_hours, backup_retention_days, restore_drill_interval_days, backup_destination_type, updated_at FROM operations_policy WHERE singleton_key = '1'");
        if (false === $row) {
            throw new \RuntimeException('Operations policy is unavailable.');
        }
        $result = [];
        foreach ($row as $key => $value) {
            if ('backup_destination_type' === $key || 'updated_at' === $key) {
                if (!\is_string($value)) {
                    throw new \RuntimeException('Operations policy is invalid.');
                }
                $result[$key] = $value;
            } else {
                if (!is_numeric($value)) {
                    throw new \RuntimeException('Operations policy is invalid.');
                }
                $result[$key] = (int) $value;
            }
        }

        return $result;
    }

    public function updateRetention(array $values): void
    {
        $update = ['retention_automation_enabled' => true === ($values['retention_automation_enabled'] ?? false) ? 1 : 0];
        foreach (self::RETENTION_BOUNDS as $key => [$minimum, $maximum]) {
            $update[$key] = $this->boundedInteger($values[$key] ?? null, $minimum, $maximum);
        }
        $update['updated_at'] = Identity::now();
        $this->connection->update('operations_policy', $update, ['singleton_key' => '1']);
    }

    public function updateBackup(array $values): void
    {
        $destination = $values['backup_destination_type'] ?? null;
        if (!\is_string($destination) || !\in_array($destination, ['none', 'filesystem'], true)) {
            throw new \InvalidArgumentException('Backup destination type is invalid.');
        }
        $enabled = true === ($values['backup_automation_enabled'] ?? false);
        if ($enabled && 'none' === $destination) {
            throw new \InvalidArgumentException('Automated backups require an approved destination.');
        }
        $update = ['backup_automation_enabled' => $enabled ? 1 : 0, 'backup_destination_type' => $destination];
        foreach (self::BACKUP_BOUNDS as $key => [$minimum, $maximum]) {
            $update[$key] = $this->boundedInteger($values[$key] ?? null, $minimum, $maximum);
        }
        $update['updated_at'] = Identity::now();
        $this->connection->update('operations_policy', $update, ['singleton_key' => '1']);
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum): int
    {
        if (!\is_int($value) && !(\is_string($value) && 1 === preg_match('/^[0-9]{1,4}$/', $value))) {
            throw new \InvalidArgumentException('Operations policy value is invalid.');
        }
        $value = (int) $value;
        if ($minimum > $value || $maximum < $value) {
            throw new \InvalidArgumentException('Operations policy value is outside its safe bounds.');
        }

        return $value;
    }
}
