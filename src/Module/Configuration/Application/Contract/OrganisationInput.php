<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

final readonly class OrganisationInput
{
    public function __construct(
        public string $displayName,
        public string $baseUrl,
        public string $locale,
        public string $timeZone,
    ) {
    }
}
