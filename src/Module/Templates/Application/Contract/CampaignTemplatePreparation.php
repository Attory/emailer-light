<?php

declare(strict_types=1);

namespace App\Module\Templates\Application\Contract;

use App\Module\Identity\Application\Contract\AdministrativeActor;

interface CampaignTemplatePreparation
{
    /** @return list<array<string, mixed>> */
    public function campaignOptions(AdministrativeActor $actor): array;

    public function prepareContent(AdministrativeActor $actor, string $name, string $subject, string $preheader, string $html): string;

    public function publishForDraft(AdministrativeActor $actor, string $versionId): void;
}
