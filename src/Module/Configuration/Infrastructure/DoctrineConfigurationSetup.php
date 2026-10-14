<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final readonly class DoctrineConfigurationSetup implements ConfigurationSetup
{
    private const ALLOWED_LOCALES = ['en_AU', 'en_GB', 'en_US'];

    public function __construct(
        private Connection $connection,
        private SetupLifecycle $lifecycle,
        private string $projectDirectory,
    ) {
    }

    public function saveOrganisation(string $installationId, OrganisationInput $input): void
    {
        $displayName = trim($input->displayName);
        if ('' === $displayName || 160 < mb_strlen($displayName) || 1 === preg_match('/[\x00-\x1F\x7F]/u', $displayName)) {
            throw new \InvalidArgumentException('Organisation display name is invalid.');
        }

        $parts = parse_url($input->baseUrl);
        if (false === $parts
            || 'https' !== ($parts['scheme'] ?? null)
            || !\is_string($parts['host'] ?? null)
            || '' === $parts['host']
            || 1 === preg_match('/[\x00-\x20\x7F]/', $input->baseUrl)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && !\in_array($parts['path'], ['', '/'], true))
        ) {
            throw new \InvalidArgumentException('Canonical base URL must be an HTTPS origin without credentials, query, or fragment.');
        }

        if (!\in_array($input->locale, self::ALLOWED_LOCALES, true) || !\in_array($input->timeZone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Locale or time zone is unsupported.');
        }

        $this->lifecycle->mutate(function () use ($installationId, $input, $displayName): void {
            $now = self::now();
            $this->connection->executeStatement(<<<'SQL'
INSERT INTO organisation_settings
    (singleton_key, installation_uuid, display_name, canonical_base_url, locale, time_zone, created_at, updated_at)
VALUES ('1', ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    display_name = VALUES(display_name), canonical_base_url = VALUES(canonical_base_url),
    locale = VALUES(locale), time_zone = VALUES(time_zone), updated_at = VALUES(updated_at)
SQL, [$installationId, $displayName, rtrim($input->baseUrl, '/'), $input->locale, $input->timeZone, $now, $now]);
        });
    }

    public function testAndSaveMail(string $installationId, MailInput $input): void
    {
        if (false === filter_var($input->senderAddress, FILTER_VALIDATE_EMAIL)
            || ('' !== $input->replyTo && false === filter_var($input->replyTo, FILTER_VALIDATE_EMAIL))
            || '' === trim($input->senderName)
            || 160 < mb_strlen($input->senderName)
            || !\in_array($input->host, ['mailpit', '127.0.0.1', '::1'], true)
            || 1025 !== $input->port
            || 'none' !== $input->encryption
            || '' !== $input->username
            || '' !== $input->password
        ) {
            throw new \InvalidArgumentException('Only plaintext, unauthenticated local Mailpit is available during Phase 3 setup.');
        }

        $host = '::1' === $input->host ? '[::1]' : $input->host;
        $dsn = \sprintf('smtp://%s:%d', $host, $input->port);

        $this->lifecycle->mutate(function () use ($dsn, $input, $installationId): void {
            $activeInstallation = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'");
            if (!\is_string($activeInstallation) || !hash_equals($activeInstallation, $installationId)) {
                throw new \RuntimeException('Installation identity is unavailable.');
            }

            try {
                $mailer = new Mailer(Transport::fromDsn($dsn));
                $mailer->send((new Email())
                    ->from($input->senderAddress)
                    ->to('phase3-recipient@example.test')
                    ->subject('[TEST] Emailer Light Phase 3 setup')
                    ->text('Synthetic Phase 3 setup message captured by Mailpit only.'));
            } catch (\Throwable) {
                throw new \RuntimeException('Mailpit connection or synthetic message test failed.');
            }

            $this->connection->transactional(function (Connection $connection) use ($input): void {
                $existingSecret = $connection->fetchOne("SELECT credential_secret_id FROM sender_identity WHERE singleton_key = '1'");
                $now = self::now();
                $connection->executeStatement(<<<'SQL'
INSERT INTO sender_identity
    (singleton_key, transport_type, smtp_host, smtp_port, encryption_mode, smtp_username,
     credential_secret_id, sender_address, sender_name, reply_to_address, tls_peer_verification,
     test_message_at, created_at, updated_at)
VALUES ('1', 'smtp', ?, ?, 'none', NULL, NULL, ?, ?, ?, 0, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    smtp_host = VALUES(smtp_host), smtp_port = VALUES(smtp_port), encryption_mode = 'none',
    smtp_username = NULL, credential_secret_id = NULL,
    sender_address = VALUES(sender_address), sender_name = VALUES(sender_name),
    reply_to_address = VALUES(reply_to_address), tls_peer_verification = 0, one_click_dkim_confirmed = 0,
    test_message_at = VALUES(test_message_at), updated_at = VALUES(updated_at)
SQL, [
                    $input->host,
                    $input->port,
                    $input->senderAddress,
                    trim($input->senderName),
                    '' === $input->replyTo ? null : $input->replyTo,
                    $now,
                    $now,
                    $now,
                ]);

                if (\is_string($existingSecret) && '' !== $existingSecret) {
                    $connection->delete('encrypted_secret', ['id' => $existingSecret]);
                }
            });
        });
    }

    public function savePolicies(PolicyInput $input): void
    {
        if ($input->messagesPerMinute < 1 || $input->messagesPerMinute > 600
            || $input->workerConcurrency < 1 || $input->workerConcurrency > 16
            || $input->retryLimit < 1 || $input->retryLimit > 10
            || $input->maximumCampaignSize < 1 || $input->maximumCampaignSize > 1_000_000
            || $input->maximumCsvBytes < 1_024 || $input->maximumCsvBytes > 104_857_600
            || $input->maximumCsvRows < 1 || $input->maximumCsvRows > 1_000_000
            || $input->operationalRetentionDays < 1 || $input->operationalRetentionDays > 365
            || $input->deliveryRetentionDays < 1 || $input->deliveryRetentionDays > 2_555
            || !\in_array($input->workerMode, ['supervised', 'cron'], true)
            || '' === trim($input->unsubscribeFooter)
            || 500 < mb_strlen($input->unsubscribeFooter)
        ) {
            throw new \InvalidArgumentException('Delivery or privacy policy is outside the supported bounds.');
        }

        $cliAvailable = is_file($this->projectDirectory.'/bin/console') && is_executable(PHP_BINARY);
        $this->lifecycle->mutate(function () use ($input, $cliAvailable): void {
            $this->connection->transactional(function (Connection $connection) use ($input, $cliAvailable): void {
                $connection->executeStatement(<<<'SQL'
INSERT INTO delivery_policy
    (singleton_key, messages_per_minute, worker_concurrency, retry_limit, maximum_campaign_size,
     maximum_csv_bytes, maximum_csv_rows, worker_mode, worker_available, scheduler_available)
VALUES ('1', ?, ?, ?, ?, ?, ?, ?, ?, ?)
ON DUPLICATE KEY UPDATE
    messages_per_minute = VALUES(messages_per_minute), worker_concurrency = VALUES(worker_concurrency),
    retry_limit = VALUES(retry_limit), maximum_campaign_size = VALUES(maximum_campaign_size),
    maximum_csv_bytes = VALUES(maximum_csv_bytes), maximum_csv_rows = VALUES(maximum_csv_rows),
    worker_mode = VALUES(worker_mode), worker_available = VALUES(worker_available),
    scheduler_available = VALUES(scheduler_available)
SQL, [
                    $input->messagesPerMinute,
                    $input->workerConcurrency,
                    $input->retryLimit,
                    $input->maximumCampaignSize,
                    $input->maximumCsvBytes,
                    $input->maximumCsvRows,
                    $input->workerMode,
                    (int) $cliAvailable,
                    (int) $cliAvailable,
                ]);

                $connection->executeStatement(<<<'SQL'
INSERT INTO privacy_policy
    (singleton_key, open_tracking, click_tracking, rsvp_enabled, operational_retention_days,
     delivery_retention_days, suppression_mode, unsubscribe_footer)
VALUES ('1', 0, 0, 0, ?, ?, 'global', ?)
ON DUPLICATE KEY UPDATE
    open_tracking = 0, click_tracking = 0, rsvp_enabled = 0,
    operational_retention_days = VALUES(operational_retention_days),
    delivery_retention_days = VALUES(delivery_retention_days),
    suppression_mode = 'global', unsubscribe_footer = VALUES(unsubscribe_footer)
SQL, [$input->operationalRetentionDays, $input->deliveryRetentionDays, trim($input->unsubscribeFooter)]);
            });
        });
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }
}
