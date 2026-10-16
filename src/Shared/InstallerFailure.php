<?php

declare(strict_types=1);

namespace App\Shared;

/** A bounded customer-facing category, never an exception message or credential. */
final class InstallerFailure
{
    /** @return array{installer_error: string} */
    public static function report(\Throwable $error): array
    {
        $message = strtolower($error->getMessage());
        $code = match (true) {
            str_contains($message, 'succeeded, but'), str_contains($message, 'committed update') => 'finish_required',
            str_contains($message, 'already succeeded'), str_contains($message, 'cannot be downgraded'), str_contains($message, 'as a downgrade'), str_contains($message, 'accepted transition') => 'completed_operation',
            str_contains($message, 'active claim'), str_contains($message, 'active work') => 'delivery_busy',
            str_contains($message, 'another') && str_contains($message, 'operation') => 'operation_busy',
            str_contains($message, 'interrupted'), str_contains($message, 'unfinished'), str_contains($message, 'rollback failed') => 'recovery_required',
            str_contains($message, 'schema'), str_contains($message, 'migration ledger'), str_contains($message, 'database differs') => 'schema_mismatch',
            str_contains($message, 'package'), str_contains($message, 'immutable release'), str_contains($message, 'signed predecessor') => 'package_mismatch',
            str_contains($message, 'supports only'), str_contains($message, 'source installation'), str_contains($message, 'source does not match') => 'source_unsupported',
            default => 'verification_failed',
        };

        return ['installer_error' => $code];
    }
}
