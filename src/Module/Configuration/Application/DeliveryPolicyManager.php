<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use Doctrine\DBAL\Connection;

final readonly class DeliveryPolicyManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private AuditSink $audit)
    {
    }

    /** @return array<string, mixed> */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'delivery.policy.view');
        $row = $this->connection->fetchAssociative("SELECT messages_per_minute, worker_concurrency, retry_limit, maximum_campaign_size FROM delivery_policy WHERE singleton_key = '1'");
        if (false === $row) {
            throw new \RuntimeException('Delivery policy is unavailable.');
        }

        return $row;
    }

    public function update(AdministrativeActor $actor, int $rate, int $concurrency, int $retries, int $maximumCampaignSize): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'delivery.policy.update');
        if ($rate < 1 || $rate > 600 || $concurrency < 1 || $concurrency > 16 || $retries < 1 || $retries > 10 || $maximumCampaignSize < 1 || $maximumCampaignSize > 100000) {
            throw new \InvalidArgumentException('Use 1–600 messages/minute, 1–16 concurrent deliveries, 1–10 attempts and 1–100,000 recipients.');
        }
        $this->connection->transactional(function () use ($actor, $rate, $concurrency, $retries, $maximumCampaignSize): void {
            $row = $this->connection->fetchOne("SELECT singleton_key FROM delivery_policy WHERE singleton_key = '1' FOR UPDATE");
            if (false === $row) {
                throw new \RuntimeException('Delivery policy is unavailable.');
            }
            $this->connection->update('delivery_policy', ['messages_per_minute' => $rate, 'worker_concurrency' => $concurrency, 'retry_limit' => $retries, 'maximum_campaign_size' => $maximumCampaignSize], ['singleton_key' => '1']);
            $this->audit->append(new AuditRecord($actor->id(), 'delivery.policy.updated', null, 'success', ['messages_per_minute' => $rate, 'concurrency' => $concurrency, 'retry_limit' => $retries, 'maximum_campaign_size' => $maximumCampaignSize]));
        });
    }
}
