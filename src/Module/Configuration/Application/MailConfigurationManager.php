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
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private SecretVault $vault, private AuditSink $audit)
    {
    }

    /** @return array<string, mixed> */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.view');
        $row = $this->connection->fetchAssociative("SELECT smtp_host, smtp_port, encryption_mode, smtp_username, sender_address, sender_name, reply_to_address, tls_peer_verification, one_click_dkim_confirmed, credential_secret_id IS NOT NULL credential_configured, updated_at FROM sender_identity WHERE singleton_key = '1'");
        if (false === $row) {
            throw new \RuntimeException('Mail configuration is unavailable.');
        }
        if ($actor->roleRank() < AdministrativeAccessLevel::Administrator->value) {
            $row['sender_address'] = \is_string($row['sender_address']) ? (new EmailAddress($row['sender_address']))->masked() : '';
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
        $senderName = trim($senderName);
        if (1 !== preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host)
            || \in_array($host, ['localhost', 'mailpit'], true) || 1 > $port || 65535 < $port || !\in_array($encryption, ['starttls', 'tls'], true)
            || '' === $username || 254 < mb_strlen($username) || 1 === preg_match('/[\x00-\x1F\x7F]/', $username)
            || '' === $password || 4096 < \strlen($password) || '' === $senderName || 160 < mb_strlen($senderName) || 1 === preg_match('/[\x00-\x1F\x7F]/', $senderName)
        ) {
            throw new \InvalidArgumentException('Secure SMTP configuration is invalid.');
        }
        $sender = new EmailAddress($senderAddress);
        $reply = '' === trim($replyTo) ? null : new EmailAddress($replyTo);
        $this->connection->transactional(function () use ($actor, $host, $port, $encryption, $username, $password, $sender, $senderName, $reply, $oneClickDkimConfirmed): void {
            $installationId = $this->connection->fetchOne("SELECT installation_uuid FROM organisation_settings WHERE singleton_key = '1' FOR UPDATE");
            $existingId = $this->connection->fetchOne("SELECT credential_secret_id FROM sender_identity WHERE singleton_key = '1' FOR UPDATE");
            if (!\is_string($installationId)) {
                throw new \RuntimeException('Installation identity is unavailable.');
            }
            if (\is_string($existingId)) {
                $this->vault->replace($existingId, 'smtp.credential', $password, $installationId);
                $secretId = $existingId;
            } else {
                $secretId = $this->vault->store('smtp.credential', $password, $installationId);
            }
            $this->connection->update('sender_identity', ['smtp_host' => $host, 'smtp_port' => $port, 'encryption_mode' => $encryption, 'smtp_username' => $username, 'credential_secret_id' => $secretId, 'sender_address' => $sender->canonical, 'sender_name' => $senderName, 'reply_to_address' => $reply?->canonical, 'tls_peer_verification' => 1, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed ? 1 : 0, 'updated_at' => Identity::now()], ['singleton_key' => '1']);
            $this->audit->append(new AuditRecord($actor->id(), 'mail.configuration.updated', null, 'success', ['encryption' => $encryption, 'port' => $port, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed]));
        });
    }

    private function mask(string $value): string
    {
        return 4 >= mb_strlen($value) ? '••••' : mb_substr($value, 0, 2).'••••'.mb_substr($value, -2);
    }
}
