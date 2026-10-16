<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class ConfigurationReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe
{
    public function __construct(private Connection $connection, private string $canonicalUri, private string $environment)
    {
    }

    public function findings(): array
    {
        $findings = [];
        $organisation = $this->connection->fetchAssociative("SELECT canonical_base_url FROM organisation_settings WHERE singleton_key = '1'");
        if (false === $organisation || !\is_string($organisation['canonical_base_url']) || (!str_starts_with($organisation['canonical_base_url'], 'https://') && !\in_array($this->environment, ['dev', 'test'], true))) {
            $findings[] = $this->critical('organisation_canonical_https', 'The stored canonical origin is missing or not HTTPS.', 'Set the organisation canonical URL to its externally verified HTTPS origin.');
        } elseif (!\in_array($this->environment, ['dev', 'test'], true) && rtrim($organisation['canonical_base_url'], '/') !== rtrim($this->canonicalUri, '/')) {
            $findings[] = $this->critical('canonical_origin_mismatch', 'Runtime and stored canonical origins differ.', 'Configure one exact externally verified HTTPS origin.');
        }
        $sender = $this->connection->fetchAssociative("SELECT smtp_host, smtp_port, encryption_mode, smtp_username, credential_secret_id, tls_peer_verification, sender_address, test_message_at, one_click_dkim_confirmed FROM sender_identity WHERE singleton_key = '1'");
        if (false === $sender || !\is_string($sender['sender_address']) || (!\is_string($sender['test_message_at']) || str_starts_with($sender['test_message_at'], '1970-01-01'))) {
            $findings[] = $this->critical('sender_identity', 'Email delivery is not configured or has not passed its connection test.', 'Open Mail transport settings to configure your provider and test the connection. You can prepare templates and campaigns now.');
        } elseif (!\in_array($this->environment, ['dev', 'test'], true) || !\in_array($sender['smtp_host'], ['mailpit', '127.0.0.1', '::1'], true)) {
            $port = $this->integer($sender['smtp_port']);
            if (!\in_array($sender['encryption_mode'], ['starttls', 'tls'], true) || 1 > $port || 65535 < $port || 1 !== $this->integer($sender['tls_peer_verification']) || !\is_string($sender['smtp_username']) || '' === trim($sender['smtp_username']) || !\is_string($sender['credential_secret_id']) || '' === trim($sender['credential_secret_id'])) {
                $findings[] = $this->critical('smtp_transport_security', 'Production SMTP lacks authenticated TLS with peer verification.', 'Configure STARTTLS or implicit TLS, authentication, and peer verification.');
            }
            if (1 === $this->integer($sender['one_click_dkim_confirmed'])) {
                $findings[] = ['code' => 'dkim_external_verification', 'severity' => 'warning', 'summary' => 'One-click unsubscribe depends on an external DKIM attestation.', 'remediation' => 'Verify a delivered message signs both List-Unsubscribe headers before production use.'];
            }
        }
        $privacy = $this->connection->fetchAssociative("SELECT suppression_mode FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $privacy || 'global' !== $privacy['suppression_mode']) {
            $findings[] = $this->critical('suppression_policy', 'Global suppression policy is unavailable.', 'Restore the global suppression policy before sending.');
        }

        return $findings;
    }

    /** @return array{code: string, severity: 'critical', summary: string, remediation: string} */
    private function critical(string $code, string $summary, string $remediation): array
    {
        return ['code' => $code, 'severity' => 'critical', 'summary' => $summary, 'remediation' => $remediation];
    }

    private function integer(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Configuration numeric state is invalid.');
        }

        return (int) $value;
    }
}
