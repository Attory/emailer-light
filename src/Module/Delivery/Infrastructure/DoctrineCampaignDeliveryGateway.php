<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\Delivery\Application\Message\DeliverCampaignTest;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class DoctrineCampaignDeliveryGateway implements CampaignDeliveryGateway
{
    public function __construct(private Connection $connection, private MessageBusInterface $bus, private DeliveryConfiguration $configuration)
    {
    }

    public function dispatchTest(string $testDeliveryId): void
    {
        $this->bus->dispatch(new DeliverCampaignTest($testDeliveryId));
    }

    public function dispatchTestAfter(string $testDeliveryId, int $delayMilliseconds): void
    {
        $this->bus->dispatch(new DeliverCampaignTest($testDeliveryId), [new DelayStamp($delayMilliseconds)]);
    }

    public function materializeAndDispatch(string $campaignId, int $operationVersion): void
    {
        $configuration = $this->configuration->current();
        $this->connection->transactional(function () use ($campaignId, $operationVersion, $configuration): void {
            $state = $this->connection->fetchAssociative('SELECT state, operation_version FROM campaign WHERE id = ? FOR UPDATE', [$campaignId]);
            if (false === $state || 'queueing' !== $state['state'] || $operationVersion !== $this->intValue($state['operation_version'])) {
                return;
            }
            $audience = $this->connection->fetchAllAssociative('SELECT recipient_id FROM campaign_audience_recipient WHERE campaign_id = ? ORDER BY recipient_id', [$campaignId]);
            foreach ($audience as $recipient) {
                if (!\is_string($recipient['recipient_id'])) {
                    throw new \UnexpectedValueException('Campaign audience identity is invalid.');
                }
                $id = Identity::uuidV7();
                $messageId = hash_hmac('sha256', $campaignId."\0".$recipient['recipient_id'], $configuration['installation_id']).'@emailer-light.invalid';
                $this->connection->executeStatement('INSERT IGNORE INTO campaign_recipient (id, campaign_id, recipient_id, state, attempt_count, next_attempt_at, claimed_at, accepted_at, terminal_at, message_id, operation_version, last_failure_class, updated_at) VALUES (?, ?, ?, ?, 0, ?, NULL, NULL, NULL, ?, ?, NULL, ?)', [$id, $campaignId, $recipient['recipient_id'], 'pending', Identity::now(), $messageId, $operationVersion, Identity::now()]);
            }
            $this->connection->update('campaign', ['state' => 'sending', 'updated_at' => Identity::now()], ['id' => $campaignId]);
        });
        $this->dispatchDue($campaignId);
    }

    public function dispatchDue(string $campaignId): void
    {
        $limit = max(1, $this->intValue($this->connection->fetchOne("SELECT LEAST(1000, COALESCE(c.messages_per_minute, d.messages_per_minute), d.messages_per_minute) FROM campaign c CROSS JOIN delivery_policy d WHERE c.id = ? AND d.singleton_key = '1'", [$campaignId])));
        $rows = $this->connection->fetchAllAssociative("SELECT r.id, r.operation_version, r.next_attempt_at FROM campaign_recipient r INNER JOIN campaign c ON c.id = r.campaign_id WHERE r.campaign_id = ? AND c.state = 'sending' AND r.state IN ('pending', 'retry') AND (r.next_attempt_at IS NULL OR r.next_attempt_at <= UTC_TIMESTAMP(6)) ORDER BY r.id LIMIT $limit", [$campaignId]);
        foreach ($rows as $row) {
            if (!\is_string($row['id'])) {
                continue;
            }
            $this->bus->dispatch(new DeliverCampaignRecipient($row['id'], $this->intValue($row['operation_version'])));
        }
    }

    public function dispatchAfter(string $recipientId, int $operationVersion, int $delayMilliseconds): void
    {
        $this->bus->dispatch(new DeliverCampaignRecipient($recipientId, $operationVersion), [new DelayStamp($delayMilliseconds)]);
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Delivery operation version is invalid.');
        }

        return (int) $value;
    }
}
