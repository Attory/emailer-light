<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application\Message;

final readonly class DeliverCampaignRecipient
{
    public function __construct(public string $recipientId, public int $operationVersion)
    {
    }
}
