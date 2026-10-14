<?php

declare(strict_types=1);

namespace App\Module\Audit\Application\Contract;

interface AuditSink
{
    public function append(AuditRecord $record): string;
}
