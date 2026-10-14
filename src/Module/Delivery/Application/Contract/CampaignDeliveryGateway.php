<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Contract;

interface CampaignDeliveryGateway
{
    public function dispatchTest(string $testDeliveryId): void;

    public function dispatchTestAfter(string $testDeliveryId, int $delayMilliseconds): void;

    public function materializeAndDispatch(string $campaignId, int $operationVersion): void;

    public function dispatchDue(string $campaignId): void;

    public function dispatchAfter(string $recipientId, int $operationVersion, int $delayMilliseconds): void;
}
