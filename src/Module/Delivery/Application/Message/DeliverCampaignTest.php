<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Message;

final readonly class DeliverCampaignTest
{
    public function __construct(public string $testDeliveryId)
    {
    }
}
