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
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private SecretVault $vault, private AuditSink $audit, private Contract\MailProbe $probe, private ?\App\Shared\UpdateDeliveryState $updateState = null, private ?\App\Shared\MaintenanceOperationLock $maintenance = null, private ?MailConnectionEvidence $connectionEvidence = null)
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

    public function configureSecureSmtp(AdministrativeActor $actor, string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password, string $senderAddress, string $senderName, string $replyTo, bool $oneClickDkimConfirmed = false, ?MailConnectionProof $testProof = null): bool
    {
        $this->maintenance?->acquire();
        try {
            return $this->configureWithMaintenance($actor, $host, $port, $encryption, $username, $password, $senderAddress, $senderName, $replyTo, $oneClickDkimConfirmed, $testProof);
        } finally {
            $this->maintenance?->release();
        }
    }

    private function configureWithMaintenance(AdministrativeActor $actor, string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password, string $senderAddress, string $senderName, string $replyTo, bool $oneClickDkimConfirmed, ?MailConnectionProof $testProof): bool
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

        return $this->connection->transactional(function () use ($actor, $host, $port, $encryption, $username, $password, $sender, $senderName, $reply, $oneClickDkimConfirmed, $testProof): bool {
            $installationId = $this->connection->fetchOne("SELECT installation_uuid FROM organisation_settings WHERE singleton_key = '1' FOR UPDATE");
            $existing = $this->connection->fetchAssociative("SELECT credential_secret_id, smtp_host, smtp_username FROM sender_identity WHERE singleton_key = '1' FOR UPDATE");
            $existingId = false === $existing ? null : $existing['credential_secret_id'];
            if (!\is_string($installationId)) {
                throw new \RuntimeException('Installation identity is unavailable.');
            }
            $tested = false;
            $proofCredential = $password;
            if (null !== $testProof && '' === $proofCredential && \is_string($existingId) && $host === $existing['smtp_host'] && $username === $existing['smtp_username']) {
                $proofCredential = $this->vault->reveal($existingId, 'smtp.credential', $installationId);
            }
            try {
                $tested = true === $this->connectionEvidence?->matches($testProof, $actor->id(), $host, $port, $encryption, $username, $proofCredential);
            } finally {
                sodium_memzero($proofCredential);
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
            $values = ['smtp_host' => $host, 'smtp_port' => $port, 'encryption_mode' => $encryption, 'smtp_username' => $username, 'credential_secret_id' => $secretId, 'sender_address' => $sender->canonical, 'sender_name' => $senderName, 'reply_to_address' => $reply?->canonical, 'tls_peer_verification' => 1, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed ? 1 : 0, 'test_message_at' => $tested ? Identity::now() : '1970-01-01 00:00:00.000000', 'updated_at' => Identity::now()];
            if (false === $existing) {
                $this->connection->insert('sender_identity', ['singleton_key' => '1', 'transport_type' => 'smtp', 'created_at' => Identity::now()] + $values);
            } else {
                $this->connection->update('sender_identity', $values, ['singleton_key' => '1']);
            }
            $this->audit->append(new AuditRecord($actor->id(), 'mail.configuration.updated', null, 'success', ['encryption' => $encryption, 'port' => $port, 'one_click_dkim_confirmed' => $oneClickDkimConfirmed]));

            return $tested;
        });
    }

    /** @return array{saved: bool, proof: MailConnectionProof} */
    public function testCurrentConfiguration(AdministrativeActor $actor, string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password, string $senderAddress = '', string $senderName = '', string $replyTo = '', bool $oneClickDkimConfirmed = false): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.test');
        $this->maintenance?->acquire();
        $credential = $password;
        $stored = null;
        try {
            $evidence = $this->connectionEvidence ?? throw new \RuntimeException('Connection-test evidence is unavailable.');
            $attempt = $evidence->begin($actor->id());
            $host = strtolower(trim($host));
            $username = trim($username);
            if (1 !== preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) || \in_array($host, ['localhost', 'mailpit'], true)) {
                throw new Contract\MailTestFailure('incomplete', 'host');
            }
            if (1 > $port || 65535 < $port) {
                throw new Contract\MailTestFailure('incomplete', 'port');
            }
            if (!\in_array($encryption, ['starttls', 'tls'], true)) {
                throw new Contract\MailTestFailure('incomplete', 'encryption');
            }
            if ('' === $username || 254 < mb_strlen($username) || 1 === preg_match('/[\x00-\x1F\x7F]/', $username)) {
                throw new Contract\MailTestFailure('incomplete', 'username');
            }
            if (4096 < \strlen($password)) {
                throw new Contract\MailTestFailure('incomplete', 'password');
            }
            $row = $this->connection->fetchAssociative("SELECT s.*, o.installation_uuid FROM sender_identity s CROSS JOIN organisation_settings o WHERE s.singleton_key='1' AND o.singleton_key='1'");
            $sameIdentity = false !== $row && $host === ($row['smtp_host'] ?? null) && $username === ($row['smtp_username'] ?? null) && \is_string($row['credential_secret_id'] ?? null) && '' !== $row['credential_secret_id'] && \is_string($row['installation_uuid'] ?? null);
            if ('' === $credential && !$sameIdentity) {
                throw new Contract\MailTestFailure('incomplete', 'password');
            }
            // Never reveal a saved credential to test another host or username.
            if ($sameIdentity && '' === $credential) {
                $stored = $this->vault->reveal($row['credential_secret_id'], 'smtp.credential', $row['installation_uuid']);
                $credential = $stored;
            }
            if ('' === $credential) {
                throw new Contract\MailTestFailure('incomplete', 'password');
            }
            $this->probe->check($host, $port, $encryption, $username, $credential, $senderAddress, null);
            $saved = false;
            if ($sameIdentity && null === $stored && \is_string($row['credential_secret_id'] ?? null) && \is_string($row['installation_uuid'] ?? null)) {
                try {
                    $stored = $this->vault->reveal($row['credential_secret_id'], 'smtp.credential', $row['installation_uuid']);
                } catch (\Throwable) {
                    // A replacement password can be tested even when the old
                    // secret is unreadable. No saved readiness is claimed.
                }
            }
            $sender = '';
            $reply = '';
            $senderFieldsValid = false;
            try {
                $sender = (new EmailAddress($senderAddress))->canonical;
                $reply = '' === trim($replyTo) ? '' : (new EmailAddress($replyTo))->canonical;
                $senderFieldsValid = true;
            } catch (\InvalidArgumentException) {
                // Sender details are not needed for EHLO/TLS/AUTH. They must
                // still be valid before the separate Save action can apply them.
            }
            $senderName = '' === trim($senderName) ? 'Emailer' : trim($senderName);
            if ($sameIdentity && $senderFieldsValid && \is_string($stored) && hash_equals($stored, $credential) && is_numeric($row['smtp_port'] ?? null) && $port === (int) $row['smtp_port'] && $encryption === ($row['encryption_mode'] ?? null) && '' !== $sender && $sender === ($row['sender_address'] ?? null) && $senderName === ($row['sender_name'] ?? null) && $reply === ($row['reply_to_address'] ?? '') && is_numeric($row['one_click_dkim_confirmed'] ?? null) && $oneClickDkimConfirmed === (1 === (int) $row['one_click_dkim_confirmed'])) {
                $saved = $this->recordMatchingSavedTest($row, $credential);
            }
            $proof = new MailConnectionProof($actor->id(), $evidence->fingerprint($actor->id(), $host, $port, $encryption, $username, $credential), time() + 600, $attempt);
            $this->audit->append(new AuditRecord($actor->id(), 'mail.connection.current_tested', null, 'success', ['configuration' => $saved ? 'saved' : 'unsaved']));

            return ['saved' => $saved, 'proof' => $proof];
        } finally {
            sodium_memzero($credential);
            if (\is_string($stored)) {
                sodium_memzero($stored);
            }
            $this->maintenance?->release();
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function recordMatchingSavedTest(array $snapshot, #[\SensitiveParameter] string $credential): bool
    {
        return $this->connection->transactional(function () use ($snapshot, $credential): bool {
            $current = $this->connection->fetchAssociative("SELECT * FROM sender_identity WHERE singleton_key='1' FOR UPDATE");
            if (false === $current) {
                return false;
            }
            foreach (['updated_at', 'credential_secret_id', 'smtp_host', 'smtp_port', 'encryption_mode', 'smtp_username', 'sender_address', 'sender_name', 'reply_to_address', 'one_click_dkim_confirmed'] as $field) {
                if (($current[$field] ?? null) !== ($snapshot[$field] ?? null)) {
                    return false;
                }
            }
            $id = $current['credential_secret_id'] ?? null;
            $installation = $snapshot['installation_uuid'] ?? null;
            if (!\is_string($id) || !\is_string($installation)) {
                return false;
            }
            $savedCredential = $this->vault->reveal($id, 'smtp.credential', $installation);
            try {
                if (!hash_equals($savedCredential, $credential)) {
                    return false;
                }
            } finally {
                sodium_memzero($savedCredential);
            }

            return 1 === $this->connection->update('sender_identity', ['test_message_at' => Identity::now()], ['singleton_key' => '1', 'updated_at' => $current['updated_at'], 'credential_secret_id' => $id]);
        });
    }

    public function test(AdministrativeActor $actor, ?string $destination): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'mail.configuration.test');
        $this->maintenance?->acquire();
        try {
            $this->connectionEvidence?->begin($actor->id());
            $this->testWithMaintenance($actor, $destination);
        } finally {
            $this->maintenance?->release();
        }
    }

    private function testWithMaintenance(AdministrativeActor $actor, ?string $destination): void
    {
        if (null !== $destination) {
            $this->updateState?->assertSendingAllowed();
        }
        $target = null === $destination ? null : new EmailAddress($destination);
        $row = $this->connection->fetchAssociative("SELECT s.*, o.installation_uuid FROM sender_identity s CROSS JOIN organisation_settings o WHERE s.singleton_key = '1' AND o.singleton_key = '1'");
        if (false === $row) {
            throw new Contract\MailTestFailure('incomplete');
        }
        foreach (['smtp_host', 'encryption_mode', 'smtp_username', 'credential_secret_id', 'sender_address', 'installation_uuid'] as $field) {
            if (!\is_string($row[$field]) || '' === $row[$field]) {
                throw new Contract\MailTestFailure('incomplete');
            }
        }
        if (!is_numeric($row['smtp_port'])) {
            throw new Contract\MailTestFailure('incomplete', 'port');
        }
        $credential = $this->vault->reveal($row['credential_secret_id'], 'smtp.credential', $row['installation_uuid']);
        try {
            $this->probe->check($row['smtp_host'], (int) $row['smtp_port'], $row['encryption_mode'], $row['smtp_username'], $credential, $row['sender_address'], $target?->canonical);
        } finally {
            sodium_memzero($credential);
        }
        if (1 !== $this->connection->update('sender_identity', ['test_message_at' => Identity::now()], ['singleton_key' => '1', 'updated_at' => $row['updated_at'], 'credential_secret_id' => $row['credential_secret_id']])) {
            throw new Contract\MailTestFailure('incomplete', 'saved_changed');
        }
        $this->audit->append(new AuditRecord($actor->id(), null === $target ? 'mail.connection.tested' : 'mail.message.tested', null, 'success'));
    }

    private function mask(string $value): string
    {
        return 4 >= mb_strlen($value) ? '••••' : mb_substr($value, 0, 2).'••••'.mb_substr($value, -2);
    }
}
