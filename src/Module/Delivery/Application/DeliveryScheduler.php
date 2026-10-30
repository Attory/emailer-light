<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\Contract\SendingNotReady;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class DeliveryScheduler
{
    public function __construct(private Connection $connection, private CampaignDeliveryGateway $gateway, private LockFactory $lockFactory, private RecipientUnsubscribeLinks $unsubscribeLinks, private SendingGate $sendingGate, private InterruptedDeliveryRecovery $recovery, private MaintenanceOperationLock $maintenance, private ?Contract\DeliveryControl $control = null)
    {
    }

    /** @return array{scheduled: int, reconciled: int} */
    public function run(string $schedulerId): array
    {
        $lock = $this->lockFactory->createLock('emailer-delivery-scheduler', 55.0);
        if (!$lock->acquire()) {
            return ['scheduled' => 0, 'reconciled' => 0];
        }
        try {
            if (!$this->acceptingWork()) {
                if ($this->maintenance->tryAcquire()) {
                    try {
                        $this->heartbeat($schedulerId);
                        $this->recovery->recover();
                    } finally {
                        $this->maintenance->release();
                    }
                } elseif ($this->committedHandoffPermitsHeartbeat()) {
                    // All outstanding installer cores are durably committed
                    // and old writers are fenced. This is a real scheduler
                    // run, but it may only heartbeat: no recovery or queue mutation.
                    $this->heartbeat($schedulerId);
                }
                $this->assertReady();
                throw new \LogicException('Sending gate allowed explicitly quiesced delivery.');
            }
            $this->heartbeat($schedulerId);
            $this->recovery->recover();
            $this->unsubscribeLinks->purgeExpired();
            $this->assertReady();
            $due = $this->connection->fetchAllAssociative("SELECT id, operation_version FROM campaign WHERE state = 'scheduled' AND scheduled_at <= UTC_TIMESTAMP(6) ORDER BY scheduled_at LIMIT 100");
            $scheduled = 0;
            foreach ($due as $row) {
                if (!\is_string($row['id'])) {
                    continue;
                }
                $storedVersion = $this->intValue($row['operation_version']);
                $version = 1 + $storedVersion;
                if (1 !== $this->connection->executeStatement("UPDATE campaign SET state = 'queueing', scheduled_at = NULL, operation_version = ?, updated_at = ? WHERE id = ? AND state = 'scheduled' AND operation_version = ?", [$version, Identity::now(), $row['id'], $storedVersion])) {
                    continue;
                }
                $this->gateway->materializeAndDispatch($row['id'], $version);
                ++$scheduled;
            }
            $campaigns = $this->connection->fetchFirstColumn("SELECT DISTINCT campaign_id FROM campaign_recipient WHERE state IN ('pending', 'retry') AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP(6)) LIMIT 100");
            $reconciled = 0;
            foreach ($campaigns as $campaignId) {
                if (\is_string($campaignId)) {
                    $this->gateway->dispatchDue($campaignId);
                    ++$reconciled;
                }
            }

            return ['scheduled' => $scheduled, 'reconciled' => $reconciled];
        } finally {
            $lock->release();
        }
    }

    private function committedHandoffPermitsHeartbeat(): bool
    {
        $state = $this->control?->status();
        if (null === $state) {
            return false;
        }
        $handoff = false;
        foreach ($state['holds'] as $hold) {
            if (!\in_array($hold['kind'] ?? null, ['host', 'native', 'edition'], true)) {
                continue;
            }
            if (\in_array($hold['phase'] ?? null, ['active', 'failed'], true)) {
                return false; // No runtime writes while any installer core is mutable.
            }
            $handoff = $handoff || 'handoff' === ($hold['phase'] ?? null);
        }

        return $handoff;
    }

    private function heartbeat(string $schedulerId): void
    {
        $now = Identity::now();
        $this->connection->executeStatement("INSERT INTO worker_heartbeat (worker_id, worker_kind, schema_version, started_at, heartbeat_at, stopped_at) VALUES (?, 'scheduler', 1, ?, ?, NULL) ON DUPLICATE KEY UPDATE worker_kind = 'scheduler', schema_version = 1, heartbeat_at = VALUES(heartbeat_at), stopped_at = NULL", [$schedulerId, $now, $now]);
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Scheduler numeric state is invalid.');
        }

        return (int) $value;
    }

    private function assertReady(): void
    {
        try {
            $this->sendingGate->assertReady();
        } catch (\App\Shared\UpdateSendingBlocked $blocked) {
            // Keep the presentation layer on the existing delivery contract.
            throw new SendingNotReady($blocked->getMessage(), previous: $blocked);
        }
    }

    private function acceptingWork(): bool
    {
        return 1 === $this->intValue($this->connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'"));
    }
}
