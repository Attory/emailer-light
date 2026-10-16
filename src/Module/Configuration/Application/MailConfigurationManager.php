<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class MailConfigurationManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private SecretVault $vault, private AuditSink $audit, private Contract\MailProbe $probe)
    {
    }

    /** @return array<string, mixed> */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.view');
        $row = $this->connection->fetchAssociative("SELECT smtp_host, smtp_port, encryption_mode, smtp_username, sender_address, sender_name, reply_to_address, tls_peer_verification, one_click_dkim_confirmed, credential_secret_id IS NOT NULL credential_configured, test_message_at, updated_at FROM sender_identity WHERE singleton_key = '1'");
        if (false === $row) {
            $row = ['smtp_host' => '', 'smtp_port' => 587, 'encryption_mode' => 'starttls', 'smtp_username' => '', 'sender_address' => '', 'sender_name' => '', 'reply_to_address' => null, 'tls_peer_verification' => 1, 'one_click_dkim_confirmed' => 0, 'credential_configured' => 0, 'test_message_at' => null, 'updated_at' => null];
        }
        if ($actor->roleRank() < AdministrativeAccessLevel::Administrator->value) {
            $row['sender_address'] = \is_string($row['sender_address']) && '' !== $row['sender_address'] ? (new EmailAddress($row['sender_address']))->masked() : '';
            $row['reply_to_address'] = \is_string($row['reply_to_address']) ? (new EmailAddress($row['reply_to_address']))->masked() : null;
            $row['smtp_username'] = \is_string($row['smtp_username']) ? $this->mask($row['smtp_username']) : null;
        } else {
            $this->audit->append(new AuditRecord($actor->id(), 'mail.configuration.viewed', null, 'success'));
        }

        return $row;
    }

    public function configureSecureSmtp(AdministrativeActor $actor, string $host, int $port, string $encryption, string $username, string $password, string $senderAddress, string $senderName, string $replyTo, bool $oneClickDkimConfirmed = false): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.update');
        $host = strtolower(trim($host));
        $username = trim($username);
        $senderName = '' === trim($senderName) ? 'Emailer' : trim($senderName);
        if (1 !== preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)
            || \in_array($host, ['localhost', 'mailpit'], true) || 1 > $port || 65535 < $port || !\in_array($encryption, ['starttls', 'tls'], true)
            || '' === $username || 254 < mb_strlen($username) || 1 === preg_match('/[\x00-\x1F\x7F]/', $username)
            || 4096 < \strlen($password) || '' === $senderName || 160 < mb_strlen($senderName) || 1 === preg_match('/[\x00-\x1F\x7F]/', $senderName)
        ) {
            throw new \InvalidArgumentException('Secure SMTP configuration is invalid.');
        }
        $sender = new EmailAddress($senderAddress);
        $reply = '' === trim($replyTo) ? null : new EmailAddress($replyTo);
        $this->connection->transactional(function () use ($actor, $host, $port, $encryption, $username, $password, $sender, $senderName, $reply, $oneClickDkimConfirmed): void {
            $installationId = $this->connection->fetchOne("SELECT installation_uuid FROM organisation_settings WHERE singleton_key = '1' FOR UPDATE");
            $existing = $this->connection->fetchAssociative("SELECT credential_secret_id, smtp_host, smtp_username FROM sender_identity WHERE singleton_key = '1' FOR UPDATE");
            $existingId = false === $existing ? null : $existing['credential_secret_id'];
            if (!\is_string($installationId)) {
                throw new \RuntimeException('Installation identity is unavailable.');
            }
            if ('' === $password) {
                if (!\is_string($existingId) || '' === $existingId || $host !== $existing['smtp_host'] || $username !== $existing['smtp_username']) {
                    throw new \InvalidArgumentException('Enter the SMTP password for a new provider or changed login.');
                }
                $secretId = $existingId;
            } elseif (\is_string($existingId)) {
                $this->vault->replace($existingId, 'smtp.credential', $password, $installationId);
                $secretId = $existingId;
            } else {
                $secretId = $this->vault->store('smtp.credential', $password, $installationId);
            }
            $values = ['smtp_host' => $host, 'smtp_port' => $port, 'encryption_mode' => $encryption, 'smtp_username' => $username, 'credential_secret_id' => $secretId, 'sender_address' => $sender->canonical, 'sender_name' => $senderName, 'reply_to_address' => $reply?->canonical, 'tls_peer_verification' => 1, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed ? 1 : 0, 'test_message_at' => '1970-01-01 00:00:00.000000', 'updated_at' => Identity::now()];
            if (false === $existing) {
                $this->connection->insert('sender_identity', ['singleton_key' => '1', 'transport_type' => 'smtp', 'created_at' => Identity::now()] + $values);
            } else {
                $this->connection->update('sender_identity', $values, ['singleton_key' => '1']);
            }
            $this->audit->append(new AuditRecord($actor->id(), 'mail.configuration.updated', null, 'success', ['encryption' => $encryption, 'port' => $port, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed]));
        });
    }

    public function test(AdministrativeActor $actor, ?string $destination): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.test');
        $target = null === $destination ? null : new EmailAddress($destination);
        $row = $this->connection->fetchAssociative("SELECT s.*, o.installation_uuid FROM sender_identity s CROSS JOIN organisation_settings o WHERE s.singleton_key = '1' AND o.singleton_key = '1'");
        if (false === $row) {
            throw new \RuntimeException('Add your email provider in Mail settings before testing.');
        }
        foreach (['smtp_host', 'encryption_mode', 'smtp_username', 'credential_secret_id', 'sender_address', 'installation_uuid'] as $field) {
            if (!\is_string($row[$field]) || '' === $row[$field]) {
                throw new \RuntimeException('Complete your secure SMTP settings before testing.');
            }
        }
        if (!is_numeric($row['smtp_port'])) {
            throw new \RuntimeException('SMTP port is unavailable.');
        }
        $credential = $this->vault->reveal($row['credential_secret_id'], 'smtp.credential', $row['installation_uuid']);
        try {
            $this->probe->check($row['smtp_host'], (int) $row['smtp_port'], $row['encryption_mode'], $row['smtp_username'], $credential, $row['sender_address'], $target?->canonical);
        } finally {
            sodium_memzero($credential);
        }
        if (1 !== $this->connection->update('sender_identity', ['test_message_at' => Identity::now()], ['singleton_key' => '1', 'updated_at' => $row['updated_at'], 'credential_secret_id' => $row['credential_secret_id']])) {
            throw new \RuntimeException('Mail settings changed during the test. Test the current settings again.');
        }
        $this->audit->append(new AuditRecord($actor->id(), null === $target ? 'mail.connection.tested' : 'mail.message.tested', null, 'success'));
    }

    private function mask(string $value): string
    {
        return 4 >= mb_strlen($value) ? '••••' : mb_substr($value, 0, 2).'••••'.mb_substr($value, -2);
    }
}
