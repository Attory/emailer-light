<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use Doctrine\DBAL\Connection;

final readonly class DoctrineDeliveryConfiguration implements DeliveryConfiguration
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): array
    {
        $row = $this->connection->fetchAssociative("SELECT o.installation_uuid, s.smtp_host, s.smtp_port, s.encryption_mode, s.smtp_username, s.credential_secret_id, s.tls_peer_verification, s.sender_address, s.sender_name, s.reply_to_address, s.one_click_dkim_confirmed, p.unsubscribe_footer, p.open_tracking, p.click_tracking, p.rsvp_enabled, p.rsvp_retention_days, p.rsvp_repeat_policy, d.messages_per_minute, d.worker_concurrency, d.retry_limit, d.maximum_campaign_size FROM organisation_settings o CROSS JOIN sender_identity s CROSS JOIN privacy_policy p CROSS JOIN delivery_policy d WHERE o.singleton_key = '1' AND s.singleton_key = '1' AND p.singleton_key = '1' AND d.singleton_key = '1'");
        if (false === $row) {
            throw new \RuntimeException('Delivery configuration is incomplete.');
        }
        foreach (['installation_uuid', 'smtp_host', 'encryption_mode', 'sender_address', 'sender_name'] as $key) {
            if (!\is_string($row[$key]) || '' === $row[$key]) {
                throw new \UnexpectedValueException('Delivery configuration is invalid.');
            }
        }

        return [
            'installation_id' => $row['installation_uuid'], 'host' => $row['smtp_host'], 'port' => $this->intValue($row['smtp_port']),
            'encryption' => $row['encryption_mode'], 'username' => \is_string($row['smtp_username']) ? $row['smtp_username'] : null,
            'credential_id' => \is_string($row['credential_secret_id']) ? $row['credential_secret_id'] : null,
            'verify_peer' => 1 === $this->intValue($row['tls_peer_verification']), 'sender_address' => $row['sender_address'], 'sender_name' => $row['sender_name'],
            'reply_to' => \is_string($row['reply_to_address']) ? $row['reply_to_address'] : null,
            'one_click_dkim_confirmed' => 1 === $this->intValue($row['one_click_dkim_confirmed']),
            'unsubscribe_footer' => $this->stringValue($row['unsubscribe_footer']), 'messages_per_minute' => $this->intValue($row['messages_per_minute']),
            'open_tracking' => 1 === $this->intValue($row['open_tracking']), 'click_tracking' => 1 === $this->intValue($row['click_tracking']),
            'rsvp_enabled' => 1 === $this->intValue($row['rsvp_enabled']), 'rsvp_retention_days' => $this->intValue($row['rsvp_retention_days']), 'rsvp_repeat_policy' => $this->stringValue($row['rsvp_repeat_policy']),
            'concurrency' => $this->intValue($row['worker_concurrency']), 'retry_limit' => $this->intValue($row['retry_limit']), 'max_campaign_size' => $this->intValue($row['maximum_campaign_size']),
        ];
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Delivery configuration number is invalid.');
        }

        return (int) $value;
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new \UnexpectedValueException('Delivery configuration text is invalid.');
        }

        return $value;
    }
}
