<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

final readonly class SetupProgress
{
    public function __construct(
        public ?string $installationId,
        public bool $databaseReady,
        public bool $keyReady,
        public bool $organisationReady,
        public bool $administratorReady,
        public bool $mailReady,
        public bool $policyReady,
        public bool $capabilityReady,
        public bool $completed,
    ) {
    }
}
