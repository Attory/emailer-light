<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Module\Backup\Application\Contract\ExternalBackupDestination;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Module\Release\Application\Contract\RuntimeVerificationFailed;

/** Preserve the already-approved backup policy; never configure a destination. */
final readonly class RecoveryBackupCopy
{
    public function __construct(private ExternalBackupDestination $destination, private OperationsPolicyReader $policy)
    {
    }

    /** @param array{id: string, path: string, sha256: string, bytes: int} $backup */
    public function preserve(array $backup): void
    {
        $policy = $this->policy->current();
        if (1 !== (int) ($policy['backup_automation_enabled'] ?? 0)) {
            return;
        }
        if ('filesystem' !== ($policy['backup_destination_type'] ?? null) || !$this->destination->available()) {
            throw new RuntimeVerificationFailed(['backup_destination']);
        }
        try {
            // The existing destination verifies copied ciphertext and records
            // success. No retention, mail or unrelated operations are run.
            $this->destination->transfer($backup);
        } catch (\Throwable) {
            throw new RuntimeVerificationFailed(['backup_external_recency']);
        }
    }
}
