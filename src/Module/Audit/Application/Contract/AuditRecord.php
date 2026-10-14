<?php

declare(strict_types=1);

namespace App\Module\Audit\Application\Contract;

final readonly class AuditRecord
{
    /** @param array<string, bool|int|string|null> $summary */
    public function __construct(
        public ?string $actorUserId,
        public string $action,
        public ?string $targetId,
        public string $result,
        public array $summary = [],
        public ?string $sourceHash = null,
    ) {
    }
}
