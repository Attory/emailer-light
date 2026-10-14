<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class InterruptedDeliveryRecoverer implements InterruptedDeliveryRecovery
{
    public function __construct(private Connection $connection, private AuditSink $audit)
    {
    }

    public function recover(): void
    {
        $this->connection->transactional(function (): void {
            if (1 === $this->connection->executeStatement("UPDATE delivery_runtime SET circuit_state = 'open', circuit_open_until = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE), updated_at = ? WHERE singleton_key = '1' AND circuit_state = 'half_open' AND (circuit_open_until IS NULL OR circuit_open_until < UTC_TIMESTAMP(6))", [Identity::now()])) {
                $this->audit->append(new AuditRecord(null, 'delivery.circuit.half_open_interrupted', null, 'failure'));
            }
            $rows = $this->connection->fetchAllAssociative("SELECT r.id, r.campaign_id, a.id attempt_id FROM campaign_recipient r LEFT JOIN delivery_attempt a ON a.campaign_recipient_id = r.id AND a.state = 'started' WHERE r.state = 'processing' AND r.claimed_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE) LIMIT 100 FOR UPDATE");
            foreach ($rows as $row) {
                if (!\is_string($row['id'])) {
                    continue;
                }
                if (\is_string($row['attempt_id'])) {
                    $this->connection->update('delivery_attempt', ['state' => 'ambiguous', 'failure_class' => 'ambiguous', 'finished_at' => Identity::now()], ['id' => $row['attempt_id'], 'state' => 'started']);
                    $this->connection->update('campaign_recipient', ['state' => 'ambiguous', 'last_failure_class' => 'ambiguous', 'terminal_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $row['id'], 'state' => 'processing']);
                    $this->audit->append(new AuditRecord(null, 'delivery.interrupted.ambiguous', $row['id'], 'failure'));
                } else {
                    $this->connection->update('campaign_recipient', ['state' => 'retry', 'next_attempt_at' => Identity::now(), 'claimed_at' => null, 'updated_at' => Identity::now()], ['id' => $row['id'], 'state' => 'processing']);
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET active_claims = GREATEST(active_claims - 1, 0), updated_at = ? WHERE singleton_key = '1'", [Identity::now()]);
                if (\is_string($row['campaign_id']) && false === $this->connection->fetchOne("SELECT 1 FROM campaign_recipient WHERE campaign_id = ? AND state IN ('pending', 'processing', 'retry')", [$row['campaign_id']])) {
                    $this->connection->executeStatement("UPDATE campaign SET state = 'completed', updated_at = ? WHERE id = ? AND state = 'sending'", [Identity::now(), $row['campaign_id']]);
                }
            }
        });
        $this->connection->executeStatement("UPDATE campaign_test_delivery SET state = 'failed', failure_class = 'ambiguous' WHERE state = 'processing' AND requested_at < DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 10 MINUTE)");
    }
}
