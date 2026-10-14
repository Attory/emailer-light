<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Application\Contract;

interface RecipientUnsubscribeLinks
{
    public function issue(string $campaignId, string $campaignRecipientId, string $audienceRecipientId, string $email): string;

    public function purgeExpired(int $limit = 100): int;
}
