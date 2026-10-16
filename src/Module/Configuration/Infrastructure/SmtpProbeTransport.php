<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class SmtpProbeTransport extends EsmtpTransport
{
    public function offersAuthentication(): bool
    {
        return \array_key_exists('AUTH', $this->getCapabilities());
    }
}
