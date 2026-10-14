<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\DeliveryTransportFailure;
use App\Module\Delivery\Application\Contract\OutboundMessage;
use App\Module\Secrets\Application\Contract\SecretVault;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

final readonly class SymfonySmtpDeliveryTransport implements DeliveryTransport
{
    public function __construct(private DeliveryConfiguration $configuration, private SecretVault $vault, private OutboundMimeMessageFactory $messages, private string $environment)
    {
    }

    public function send(OutboundMessage $message): string
    {
        $configuration = $this->configuration->current();
        $local = \in_array($configuration['host'], ['mailpit', '127.0.0.1', '::1'], true) && 1025 === $configuration['port'] && 'none' === $configuration['encryption'] && null === $configuration['username'] && null === $configuration['credential_id'] && !$configuration['verify_peer'];
        if (\in_array($this->environment, ['dev', 'test'], true) && !$local) {
            throw new DeliveryTransportFailure('permanent', 'Non-local SMTP is prohibited in this environment.', affectsCircuit: true);
        }
        $secure = \in_array($configuration['encryption'], ['starttls', 'tls'], true) && $configuration['verify_peer'] && null !== $configuration['username'] && null !== $configuration['credential_id'];
        if ('prod' === $this->environment && !$secure) {
            throw new DeliveryTransportFailure('permanent', 'Production SMTP configuration is not secure.', affectsCircuit: true);
        }

        $transport = new AuthenticatedEsmtpTransport($configuration['host'], $configuration['port'], 'tls' === $configuration['encryption'], !$local);
        $transport->setAutoTls('starttls' === $configuration['encryption']);
        $transport->setRequireTls(!$local);
        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout($local ? 10.0 : 30.0);
            if (!$local) {
                $stream->setStreamOptions(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $configuration['host']]]);
            }
        }
        if (!$local) {
            try {
                $password = $this->vault->reveal((string) $configuration['credential_id'], 'smtp.credential', $configuration['installation_id']);
                $transport->setUsername((string) $configuration['username']);
                $transport->setPassword($password);
            } catch (\Throwable $exception) {
                throw new DeliveryTransportFailure('permanent', 'SMTP credential is unavailable.', $exception, true);
            }
        }
        $email = $this->messages->create($message);
        try {
            $sent = $transport->send($email);

            if (null === $sent) {
                throw new DeliveryTransportFailure('ambiguous', 'SMTP delivery outcome is ambiguous.');
            }

            return $sent->getMessageId();
        } catch (TransportExceptionInterface $exception) {
            $code = $exception->getCode();
            $class = $exception instanceof SmtpAuthenticationRequired || (500 <= $code && 600 > $code) ? 'permanent' : 'transient';
            throw new DeliveryTransportFailure($class, 'SMTP delivery was not accepted.', $exception, $exception instanceof SmtpAuthenticationRequired || 'transient' === $class);
        } catch (\Throwable $exception) {
            throw new DeliveryTransportFailure('ambiguous', 'SMTP delivery outcome is ambiguous.', $exception);
        }
    }
}
