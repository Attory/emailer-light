<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Backup\Application\Contract\BackupContributor;

final readonly class DeliveryBackupContributor implements BackupContributor
{
    public function tables(): array
    {
        return ['campaign', 'campaign_content_snapshot', 'campaign_audience_snapshot', 'campaign_audience_recipient', 'campaign_test_delivery', 'campaign_recipient', 'delivery_attempt', 'delivery_runtime', 'delivery_domain_rate', 'worker_heartbeat', 'messenger_messages', 'recipient_token'];
    }

    public function assetRoots(): array
    {
        return [];
    }
}
