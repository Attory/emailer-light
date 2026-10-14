<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Application;

use App\Module\Backup\Application\Contract\BackupRetentionOperations;
use App\Module\Housekeeping\Application\Contract\RetentionOperations;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class Housekeeper implements RetentionOperations
{
    private const BATCH = 200;

    public function __construct(private Connection $connection, private LockFactory $locks, private MaintenanceOperationLock $maintenance, private OperationsPolicyReader $policy, private BackupRetentionOperations $backupRetention)
    {
    }

    /** @return array{sessions: int, queue: int, dead_letter: int, delivery_attempts: int, health: int, analytics: int, rsvp: int, backups: int} */
    public function run(bool $dryRun): array
    {
        $this->maintenance->acquire();
        try {
            return $this->runWithLock($dryRun);
        } finally {
            $this->maintenance->release();
        }
    }

    /** @return array{sessions: int, queue: int, dead_letter: int, delivery_attempts: int, health: int, analytics: int, rsvp: int, backups: int} */
    private function runWithLock(bool $dryRun): array
    {
        $lock = $this->locks->createLock('emailer-housekeeping', 300.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another housekeeping run is active.');
        }
        try {
            return $this->runLocked($dryRun);
        } finally {
            $lock->release();
        }
    }

    /** @return array{sessions: int, queue: int, dead_letter: int, delivery_attempts: int, health: int, analytics: int, rsvp: int, backups: int} */
    private function runLocked(bool $dryRun): array
    {
        $started = Identity::now();
        $operations = $this->policy->current();
        $retention = $this->connection->fetchAssociative("SELECT tracking_retention_days, rsvp_retention_days FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $retention || !is_numeric($retention['tracking_retention_days']) || 1 > (int) $retention['tracking_retention_days'] || 365 < (int) $retention['tracking_retention_days'] || !is_numeric($retention['rsvp_retention_days']) || 1 > (int) $retention['rsvp_retention_days'] || 365 < (int) $retention['rsvp_retention_days']) {
            throw new \RuntimeException('Analytics retention policy is invalid.');
        }
        $analyticsCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $retention['tracking_retention_days'].' days')->format('Y-m-d H:i:s.u');
        $rsvpCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $retention['rsvp_retention_days'].' days')->format('Y-m-d H:i:s.u');
        $sessionCutoff = $this->cutoff($operations, 'revoked_session_retention_days');
        $queueCutoff = $this->cutoff($operations, 'queue_retention_days');
        $deadLetterCutoff = $this->cutoff($operations, 'dead_letter_retention_days');
        $healthCutoff = $this->cutoff($operations, 'health_retention_days');
        $attemptCutoff = $this->cutoff($operations, 'delivery_attempt_retention_days');
        $counts = [
            'sessions' => $this->count('SELECT COUNT(*) FROM (SELECT id_hash FROM user_session WHERE revoked_at IS NOT NULL AND revoked_at < ? ORDER BY revoked_at LIMIT '.self::BATCH.') expired', [$sessionCutoff]),
            'queue' => $this->count("SELECT COUNT(*) FROM (SELECT id FROM messenger_messages WHERE queue_name <> 'failed' AND delivered_at IS NOT NULL AND delivered_at < ? ORDER BY id LIMIT ".self::BATCH.') expired', [$queueCutoff]),
            'dead_letter' => $this->count("SELECT COUNT(*) FROM (SELECT id FROM messenger_messages WHERE queue_name = 'failed' AND created_at < ? ORDER BY id LIMIT ".self::BATCH.') expired', [$deadLetterCutoff]),
            'delivery_attempts' => $this->count('SELECT COUNT(*) FROM (SELECT id FROM delivery_attempt WHERE finished_at IS NOT NULL AND finished_at < ? ORDER BY finished_at LIMIT '.self::BATCH.') expired', [$attemptCutoff]),
            'health' => $this->count('SELECT COUNT(*) FROM (SELECT id FROM health_check_run WHERE finished_at < ? ORDER BY finished_at LIMIT '.self::BATCH.') expired', [$healthCutoff]),
            'analytics' => $this->count('SELECT COUNT(*) FROM (SELECT campaign_recipient_id, event_type, link_index FROM analytics_aggregate WHERE last_seen_at < ? ORDER BY last_seen_at LIMIT '.self::BATCH.') expired', [$analyticsCutoff]),
            'rsvp' => $this->count('SELECT COUNT(*) FROM (SELECT campaign_recipient_id FROM rsvp_response WHERE last_submitted_at < ? ORDER BY last_submitted_at LIMIT '.self::BATCH.') expired', [$rsvpCutoff]),
            'backups' => $this->backupRetention->prune(true, (int) $operations['backup_retention_days']),
        ];
        if (!$dryRun) {
            $counts['sessions'] = (int) $this->connection->executeStatement('DELETE FROM user_session WHERE revoked_at IS NOT NULL AND revoked_at < ? ORDER BY revoked_at LIMIT '.self::BATCH, [$sessionCutoff]);
            $counts['queue'] = (int) $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name <> 'failed' AND delivered_at IS NOT NULL AND delivered_at < ? ORDER BY id LIMIT ".self::BATCH, [$queueCutoff]);
            $counts['dead_letter'] = (int) $this->connection->executeStatement("DELETE FROM messenger_messages WHERE queue_name = 'failed' AND created_at < ? ORDER BY id LIMIT ".self::BATCH, [$deadLetterCutoff]);
            $counts['delivery_attempts'] = (int) $this->connection->executeStatement('DELETE FROM delivery_attempt WHERE finished_at IS NOT NULL AND finished_at < ? ORDER BY finished_at LIMIT '.self::BATCH, [$attemptCutoff]);
            $counts['health'] = (int) $this->connection->executeStatement('DELETE FROM health_check_run WHERE finished_at < ? ORDER BY finished_at LIMIT '.self::BATCH, [$healthCutoff]);
            $counts['analytics'] = (int) $this->connection->executeStatement('DELETE FROM analytics_aggregate WHERE last_seen_at < ? ORDER BY last_seen_at LIMIT '.self::BATCH, [$analyticsCutoff]);
            $counts['rsvp'] = (int) $this->connection->executeStatement('DELETE FROM rsvp_response WHERE last_submitted_at < ? ORDER BY last_submitted_at LIMIT '.self::BATCH, [$rsvpCutoff]);
            $counts['backups'] = $this->backupRetention->prune(false, (int) $operations['backup_retention_days']);
        }
        $this->connection->insert('housekeeping_run', ['id' => Identity::uuidV7(), 'dry_run' => $dryRun ? 1 : 0, 'deleted_sessions' => $counts['sessions'], 'deleted_queue_rows' => $counts['queue'], 'deleted_dead_letter_rows' => $counts['dead_letter'], 'deleted_delivery_attempt_rows' => $counts['delivery_attempts'], 'deleted_health_runs' => $counts['health'], 'deleted_analytics_rows' => $counts['analytics'], 'deleted_rsvp_rows' => $counts['rsvp'], 'deleted_backup_files' => $counts['backups'], 'status' => 'succeeded', 'started_at' => $started, 'finished_at' => Identity::now()]);

        return $counts;
    }

    /** @param list<mixed> $parameters */
    private function count(string $sql, array $parameters = []): int
    {
        $value = $this->connection->fetchOne($sql, $parameters);
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Housekeeping count is invalid.');
        }

        return (int) $value;
    }

    /** @param array<string, int|string> $policy */
    private function cutoff(array $policy, string $key): string
    {
        $days = $policy[$key] ?? null;
        if (!\is_int($days) || 1 > $days || 730 < $days) {
            throw new \RuntimeException('Retention policy is invalid.');
        }

        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.$days.' days')->format('Y-m-d H:i:s.u');
    }
}
