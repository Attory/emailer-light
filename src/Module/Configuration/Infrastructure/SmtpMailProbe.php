<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\MailProbe;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

final readonly class SmtpMailProbe implements MailProbe
{
    public function __construct(private string $environment)
    {
    }

    public function check(string $host, int $port, string $encryption, string $username, string $password, string $sender, ?string $destination): void
    {
        // Tests must use a separate fake implementation; this service never contacts a provider in dev/CI.
        if ('prod' !== $this->environment || !\in_array($encryption, ['starttls', 'tls'], true) || '' === $username || '' === $password) {
            throw new \RuntimeException('A secure configured SMTP connection is required.');
        }
        $transport = new SmtpProbeTransport($host, $port, 'tls' === $encryption);
        $transport->setAutoTls('starttls' === $encryption);
        $transport->setRequireTls(true);
        $transport->setUsername($username);
        $transport->setPassword($password);
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout(15.0);
            $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host]]);
        }
        try {
            $transport->start();
            if (!$transport->offersAuthentication()) {
                throw new \RuntimeException('SMTP authentication was not offered.');
            }
            if (null !== $destination) {
                $transport->send((new Email())->from($sender)->to($destination)->subject('Emailer: requested test email')->text('This is the test email you explicitly requested from Emailer. No campaign or recipient list was used.'));
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Could not connect securely or sign in to your email provider. Check its hostname, port, encryption mode and password. No campaign was sent.');
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
                // Provider disconnect errors must not expose transport diagnostics.
            }
        }
    }
}
