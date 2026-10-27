<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\MailProbe;
use App\Module\Configuration\Application\Contract\MailTestFailure;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mime\Email;

final readonly class SmtpMailProbe implements MailProbe
{
    public function __construct(private string $environment, private ?LoggerInterface $logger = null)
    {
    }

    public function check(string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password, string $sender, ?string $destination): void
    {
        // Tests must use a separate fake implementation; this service never contacts a provider in dev/CI.
        if ('prod' !== $this->environment || !\in_array($encryption, ['starttls', 'tls'], true) || '' === $username || '' === $password) {
            throw new MailTestFailure('incomplete');
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
        $phase = 'connect_tls_auth';
        try {
            $transport->start();
            if (!$transport->offersAuthentication()) {
                throw new MailTestFailure('authentication');
            }
            if (null !== $destination) {
                $phase = 'send';
                $transport->send((new Email())->from($sender)->to($destination)->subject('Emailer: requested test email')->text('This is the test email you explicitly requested from Emailer. No campaign or recipient list was used.'));
            }
        } catch (\Throwable $error) {
            $failure = $error instanceof MailTestFailure ? $error : SmtpFailureClassifier::classify($error, $phase, 'send' === $phase);
            $this->logger?->warning('smtp_connection_test_failed', $failure->safeDiagnostics());
            throw $failure;
        } finally {
            try {
                $transport->stop();
            } catch (\Throwable) {
                // Provider disconnect errors must not expose transport diagnostics.
            }
        }
    }
}
