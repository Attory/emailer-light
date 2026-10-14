<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

final readonly class PolicyInput
{
    public function __construct(
        public int $messagesPerMinute,
        public int $workerConcurrency,
        public int $retryLimit,
        public int $maximumCampaignSize,
        public int $maximumCsvBytes,
        public int $maximumCsvRows,
        public int $operationalRetentionDays,
        public int $deliveryRetentionDays,
        public string $unsubscribeFooter,
        public string $workerMode,
    ) {
    }
}
