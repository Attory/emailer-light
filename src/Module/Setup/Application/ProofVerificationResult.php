<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

enum ProofVerificationResult: string
{
    case Success = 'success';
    case RetryableFailure = 'retryable_failure';
    case InvalidAuthority = 'invalid_authority';
    case Expired = 'expired';
    case AttemptsExceeded = 'attempts_exceeded';

    public function isRetryable(): bool
    {
        return self::RetryableFailure === $this;
    }
}
