<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application\Contract;

final class MailTestFailure extends \RuntimeException
{
    public readonly string $reference;

    /** @param array<string, string|int|null> $diagnostics */
    public function __construct(public readonly string $category, string $hint = '', public readonly array $diagnostics = [], bool $uncertainMessage = false)
    {
        $this->reference = bin2hex(random_bytes(8));
        $message = match ($category) {
            'incomplete' => match ($hint) {
                'password' => 'Enter the SMTP password. A saved password can be reused only when the provider hostname and username match.',
                'host' => 'Enter the provider hostname without https://, a port or a path.',
                'port' => 'Enter an SMTP port from 1 to 65535, using the port your provider specifies.',
                'username' => 'Enter the SMTP username supplied by your provider.',
                'encryption' => 'Choose STARTTLS required or Direct TLS, matching your provider’s port.',
                'saved_changed' => 'The saved configuration changed while testing. Test the current configuration again.',
                default => 'Complete the SMTP hostname, port, encryption, username and password before testing.',
            },
            'dns' => 'The provider hostname could not be resolved. Check its spelling and this server’s DNS connection.',
            'timeout' => 'The SMTP connection timed out. Check the provider’s port and your hosting provider’s outgoing SMTP firewall rules.',
            'connection' => 'The SMTP server could not be reached or refused the connection. Check the hostname, port and outgoing firewall rules.',
            'tls_certificate' => 'Required TLS or the server certificate could not be verified. Check the hostname, encryption mode and certificate chain; keep certificate verification enabled.',
            'authentication' => 'The SMTP server rejected sign-in or did not offer supported authentication. Check the username and SMTP password or provider key.',
            default => 'Emailer could not complete the test because of an internal application error. Use the reference below to investigate.',
        };
        parent::__construct($message.($uncertainMessage ? ' The requested test message’s outcome is uncertain; check its receipt before retrying. No campaign was sent.' : ' No email was sent.').' Reference: '.$this->reference);
    }

    /** @return array<string, string|int|null> */
    public function safeDiagnostics(): array
    {
        return ['reference' => $this->reference, 'category' => $this->category, ...$this->diagnostics];
    }
}
