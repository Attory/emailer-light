<?php

declare(strict_types=1);

namespace App\Module\Operations\Application;

use App\Module\Backup\Application\Contract\AutomatedBackupOperations;
use App\Module\Health\Application\Contract\ScheduledHealthOperations;
use App\Module\Housekeeping\Application\Contract\RetentionOperations;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class OperationsRunner
{
    public function __construct(private OperationsPolicyReader $policy, private ScheduledHealthOperations $health, private RetentionOperations $retention, private AutomatedBackupOperations $backups, private Connection $connection, private LockFactory $locks)
    {
    }

    /** @return array<string, string> */
    public function runDue(): array
    {
        $lock = $this->locks->createLock('emailer-operations-scheduler', 3600.0);
        if (!$lock->acquire()) {
            return ['scheduler' => 'already_running'];
        }
        try {
            $policy = $this->policy->current();
            $results = [];
            if ($this->due('doctor', (int) $policy['doctor_interval_hours'])) {
                $results['doctor'] = $this->execute('doctor', function (): string {
                    $result = $this->health->runScheduled();

                    $status = $result['status'] ?? null;
                    if (!\is_string($status) || !\in_array($status, ['healthy', 'warning', 'critical'], true)) {
                        throw new \RuntimeException('Doctor returned invalid scheduled evidence.');
                    }

                    return 'doctor_'.$status;
                });
            }
            if (1 === $policy['retention_automation_enabled'] && $this->due('retention', (int) $policy['retention_interval_hours'])) {
                $results['retention'] = $this->execute('retention', function (): string {
                    $this->retention->run(false);

                    return 'bounded_batch_completed';
                });
            }
            if (1 === $policy['backup_automation_enabled'] && $this->due('backup', (int) $policy['backup_interval_hours'])) {
                $results['backup'] = $this->execute('backup', function () use ($policy): string {
                    $this->backups->createAndTransfer((string) $policy['backup_destination_type']);

                    return 'verified_external_copy';
                });
            }

            return [] === $results ? ['scheduler' => 'nothing_due'] : $results;
        } finally {
            $lock->release();
        }
    }

    private function due(string $operation, int $intervalHours): bool
    {
        $last = $this->connection->fetchOne("SELECT finished_at FROM operations_run WHERE operation = ? AND status = 'succeeded' ORDER BY finished_at DESC LIMIT 1", [$operation]);
        if (!\is_string($last)) {
            return true;
        }
        try {
            $finished = new \DateTimeImmutable($last, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return true;
        }

        if ('doctor' === $operation) {
            $installationCompleted = $this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'");
            if (\is_string($installationCompleted)) {
                try {
                    if ($finished < new \DateTimeImmutable($installationCompleted, new \DateTimeZone('UTC'))) {
                        return true;
                    }
                } catch (\Throwable) {
                    return true;
                }
            }
        }

        return $finished->modify('+'.$intervalHours.' hours') <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param callable(): string $operation */
    private function execute(string $name, callable $operation): string
    {
        $id = Identity::uuidV7();
        $started = Identity::now();
        try {
            $detail = $operation();
            $status = 'succeeded';
        } catch (\Throwable) {
            $detail = $name.'_failed';
            $status = 'failed';
        }
        $this->connection->insert('operations_run', ['id' => $id, 'operation' => $name, 'status' => $status, 'detail_code' => $detail, 'started_at' => $started, 'finished_at' => Identity::now()]);

        return $status.':'.$detail;
    }
}
