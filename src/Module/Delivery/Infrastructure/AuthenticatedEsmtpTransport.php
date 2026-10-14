<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

final class AuthenticatedEsmtpTransport extends EsmtpTransport
{
    public function __construct(string $host, int $port, bool $directTls, private readonly bool $requireAuthentication)
    {
        parent::__construct($host, $port, $directTls);
    }

    public function start(): void
    {
        parent::start();
        if (!$this->requireAuthentication) {
            return;
        }
        try {
            $authenticationAvailable = \array_key_exists('AUTH', $this->getCapabilities());
        } catch (\Throwable) {
            $authenticationAvailable = false;
        }
        if (!$authenticationAvailable) {
            $this->stop();
            throw new SmtpAuthenticationRequired('SMTP authentication is required but unavailable.');
        }
    }
}

final class SmtpAuthenticationRequired extends TransportException
{
}
