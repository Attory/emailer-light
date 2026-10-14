<?php

declare(strict_types=1);

namespace App\Module\Health\Application\Contract;

interface ReadinessProbe
{
    /** @return list<array{code: string, severity: 'critical'|'warning', summary: string, remediation: string}> */
    public function findings(): array;
}
