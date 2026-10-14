<?php

declare(strict_types=1);

namespace App\Module\Analytics\Application\Contract;

interface RecipientAnalyticsLinks
{
    /** @return array{view: string, open: ?string, click: ?string} */
    public function issue(string $campaignId, string $campaignRecipientId, string $audienceRecipientId, string $email, bool $openTracking, bool $clickTracking): array;
}
