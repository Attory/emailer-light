<?php

declare(strict_types=1);

namespace App\Module\Release\Application\Contract;

/** Only bounded public readiness codes cross the installer boundary. */
final class RuntimeVerificationFailed extends \RuntimeException
{
    /** @var list<string> */
    public readonly array $blockers;

    /** @param array<array-key, mixed> $blockers */
    public function __construct(array $blockers)
    {
        $valid = [] !== $blockers && 64 >= \count($blockers);
        $safe = [];
        foreach ($blockers as $code) {
            if (!\is_string($code) || 1 !== preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $code)) {
                $valid = false;
            } else {
                $safe[] = $code;
            }
        }
        $this->blockers = $valid ? array_values(array_unique($safe)) : ['runtime_verification'];
        parent::__construct('Application structural verification needs attention. Delivery remains held.');
    }
}
