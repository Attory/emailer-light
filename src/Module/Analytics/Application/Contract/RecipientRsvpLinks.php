<?php

declare(strict_types=1);

namespace App\Module\Analytics\Application\Contract;

interface RecipientRsvpLinks
{
    public function issueRsvp(string $campaignId, string $campaignRecipientId, string $audienceRecipientId, string $email): string;
}
