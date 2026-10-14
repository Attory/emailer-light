<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\RecipientRuntimeConfiguration;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Module\Suppression\Application\Contract\RecipientSuppression;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class RecipientTokenService implements RecipientUnsubscribeLinks
{
    private const PURPOSE = 'unsubscribe';
    private const SECRET_TYPE = 'recipient.token.key';
    private const TOKEN_LIFETIME = '365 days';
    private const KEY_OVERLAP = '365 days';
    private const RETENTION_GRACE = '30 days';

    public function __construct(
        private Connection $connection,
        private RecipientRuntimeConfiguration $configuration,
        private SecretVault $vault,
        private RecipientSuppression $suppressions,
        private RecipientTokenCodec $codec,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
    ) {
    }

    public function issue(string $campaignId, string $campaignRecipientId, string $audienceRecipientId, string $email): string
    {
        $this->uuid($campaignId);
        $this->uuid($campaignRecipientId);
        $this->uuid($audienceRecipientId);
        $address = new EmailAddress($email);
        $runtime = $this->configuration->current();

        return $this->connection->transactional(function () use ($campaignId, $campaignRecipientId, $audienceRecipientId, $address, $runtime): string {
            [$version, $secretId] = $this->currentKey($runtime['installation_id']);
            $row = $this->connection->fetchAssociative('SELECT * FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = ? FOR UPDATE', [$campaignRecipientId, self::PURPOSE]);
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            if (false === $row) {
                $id = Identity::uuidV7();
                $handle = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
                $expiresAt = $now->modify('+'.self::TOKEN_LIFETIME)->format('Y-m-d H:i:s.u');
                $this->connection->executeStatement('INSERT IGNORE INTO recipient_token (id, handle, campaign_id, campaign_recipient_id, recipient_id, email_canonical, purpose, key_version, issued_at, expires_at, revoked_at, last_used_at, use_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, 0)', [$id, $handle, $campaignId, $campaignRecipientId, $audienceRecipientId, $address->canonical, self::PURPOSE, $version, Identity::now(), $expiresAt]);
                $row = $this->connection->fetchAssociative('SELECT * FROM recipient_token WHERE campaign_recipient_id = ? AND purpose = ? FOR UPDATE', [$campaignRecipientId, self::PURPOSE]);
            }
            if (false === $row || !\is_string($row['handle']) || !\is_string($row['expires_at']) || !\is_string($row['campaign_id']) || !\is_string($row['campaign_recipient_id']) || !\is_string($row['recipient_id']) || !is_numeric($row['key_version'])) {
                throw new \RuntimeException('Recipient token could not be issued.');
            }
            if ($row['email_canonical'] !== $address->canonical || $row['campaign_id'] !== $campaignId || $row['recipient_id'] !== $audienceRecipientId) {
                throw new \RuntimeException('Recipient token scope does not match.');
            }
            $storedVersion = (int) $row['key_version'];
            if ($storedVersion !== $version) {
                $keyRow = $this->connection->fetchAssociative('SELECT secret_id FROM recipient_token_key WHERE key_version = ?', [$storedVersion]);
                if (false === $keyRow || !\is_string($keyRow['secret_id'])) {
                    throw new \RuntimeException('Recipient token key is unavailable.');
                }
                $secretId = $keyRow['secret_id'];
            }
            $secret = $this->decodeSecret($this->vault->reveal($secretId, self::SECRET_TYPE, $runtime['installation_id']));
            try {
                $token = $this->codec->encode($storedVersion, $row['handle'], self::PURPOSE, $row['campaign_id'], $row['campaign_recipient_id'], $row['recipient_id'], $row['expires_at'], $secret);
            } finally {
                sodium_memzero($secret);
            }

            return $runtime['canonical_origin'].'/r/'.rawurlencode($token).'/unsubscribe';
        });
    }

    public function inspect(string $token): void
    {
        $this->validTokenRow($token, false);
    }

    public function unsubscribe(string $token): void
    {
        $this->connection->transactional(function () use ($token): void {
            $row = $this->validTokenRow($token, true);
            $this->suppressions->suppressFromRecipientAction($this->stringValue($row['email_canonical']));
            $this->connection->executeStatement('UPDATE recipient_token SET last_used_at = ?, use_count = use_count + 1 WHERE id = ?', [Identity::now(), $this->stringValue($row['id'])]);
        });
    }

    public function rotateKey(AdministrativeActor $actor): int
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'recipient_token.key.rotate');
        $runtime = $this->configuration->current();

        return $this->connection->transactional(function () use ($actor, $runtime): int {
            [$current] = $this->currentKey($runtime['installation_id']);
            if (65_535 <= $current) {
                throw new \RuntimeException('Recipient token key version is exhausted.');
            }
            $next = $current + 1;
            $secret = random_bytes(32);
            try {
                $secretId = $this->vault->store(self::SECRET_TYPE, sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $runtime['installation_id']);
            } finally {
                sodium_memzero($secret);
            }
            $this->connection->update('recipient_token_key', ['active' => 0, 'verify_until' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+'.self::KEY_OVERLAP)->format('Y-m-d H:i:s.u')], ['key_version' => $current, 'active' => 1]);
            $this->connection->insert('recipient_token_key', ['key_version' => $next, 'secret_id' => $secretId, 'active' => 1, 'verify_until' => null, 'created_at' => Identity::now()]);
            $this->connection->update('recipient_token_key_state', ['current_version' => $next, 'updated_at' => Identity::now()], ['singleton_key' => '1']);
            $this->audit->append(new AuditRecord($actor->id(), 'recipient_token.key.rotated', null, 'success', ['key_version' => $next]));

            return $next;
        });
    }

    public function purgeExpired(int $limit = 100): int
    {
        if (1 > $limit || 1_000 < $limit) {
            throw new \InvalidArgumentException('Recipient token purge limit is invalid.');
        }
        $cutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.self::RETENTION_GRACE)->format('Y-m-d H:i:s.u');
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM recipient_token WHERE expires_at < ? ORDER BY expires_at LIMIT '.$limit, [$cutoff]);
        $deleted = 0;
        foreach ($ids as $id) {
            if (\is_string($id)) {
                $deleted += (int) $this->connection->delete('recipient_token', ['id' => $id]);
            }
        }

        return $deleted;
    }

    /** @return array<string, mixed> */
    private function validTokenRow(string $token, bool $lock): array
    {
        $parsed = $this->codec->parse($token);
        if (null === $parsed) {
            throw new RecipientTokenInvalid();
        }
        $sql = 'SELECT t.*, k.secret_id, k.active, k.verify_until FROM recipient_token t INNER JOIN recipient_token_key k ON k.key_version = t.key_version WHERE t.handle = ? AND t.purpose = ?'.($lock ? ' FOR UPDATE' : '');
        $row = $this->connection->fetchAssociative($sql, [$parsed['handle'], self::PURPOSE]);
        if (false === $row || !is_numeric($row['key_version']) || $parsed['version'] !== (int) $row['key_version'] || !\is_string($row['expires_at']) || !\is_string($row['secret_id'])) {
            throw new RecipientTokenInvalid();
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $expires = new \DateTimeImmutable($row['expires_at'], new \DateTimeZone('UTC'));
        $keyUsable = 1 === $this->intValue($row['active']) || (\is_string($row['verify_until']) && new \DateTimeImmutable($row['verify_until'], new \DateTimeZone('UTC')) >= $now);
        if ($expires < $now || null !== $row['revoked_at'] || !$keyUsable) {
            throw new RecipientTokenInvalid();
        }
        $runtime = $this->configuration->current();
        try {
            $secret = $this->decodeSecret($this->vault->reveal($row['secret_id'], self::SECRET_TYPE, $runtime['installation_id']));
            $valid = $this->codec->verify($token, (int) $row['key_version'], $this->stringValue($row['handle']), self::PURPOSE, $this->stringValue($row['campaign_id']), $this->stringValue($row['campaign_recipient_id']), $this->stringValue($row['recipient_id']), $row['expires_at'], $secret);
        } catch (\Throwable) {
            throw new RecipientTokenInvalid();
        } finally {
            if (isset($secret)) {
                sodium_memzero($secret);
            }
        }
        if (!$valid) {
            throw new RecipientTokenInvalid();
        }

        return $row;
    }

    /** @return array{int, string} */
    private function currentKey(string $installationId): array
    {
        $state = $this->connection->fetchAssociative("SELECT current_version FROM recipient_token_key_state WHERE singleton_key = '1' FOR UPDATE");
        if (false === $state || !is_numeric($state['current_version'])) {
            throw new \RuntimeException('Recipient token key state is unavailable.');
        }
        $version = (int) $state['current_version'];
        if (0 === $version) {
            $version = 1;
            $secret = random_bytes(32);
            try {
                $secretId = $this->vault->store(self::SECRET_TYPE, sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $installationId);
            } finally {
                sodium_memzero($secret);
            }
            $this->connection->insert('recipient_token_key', ['key_version' => $version, 'secret_id' => $secretId, 'active' => 1, 'verify_until' => null, 'created_at' => Identity::now()]);
            $this->connection->update('recipient_token_key_state', ['current_version' => $version, 'updated_at' => Identity::now()], ['singleton_key' => '1']);

            return [$version, $secretId];
        }
        $row = $this->connection->fetchAssociative('SELECT secret_id FROM recipient_token_key WHERE key_version = ? AND active = 1', [$version]);
        if (false === $row || !\is_string($row['secret_id'])) {
            throw new \RuntimeException('Recipient token key is unavailable.');
        }

        return [$version, $row['secret_id']];
    }

    private function decodeSecret(string $encoded): string
    {
        try {
            $secret = sodium_base642bin($encoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException) {
            throw new \RuntimeException('Recipient token key is unavailable.');
        }
        if (32 !== \strlen($secret)) {
            sodium_memzero($secret);
            throw new \RuntimeException('Recipient token key is unavailable.');
        }

        return $secret;
    }

    private function uuid(string $value): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value)) {
            throw new \InvalidArgumentException('Recipient token scope is invalid.');
        }
    }

    private function stringValue(mixed $value): string
    {
        if (!\is_string($value)) {
            throw new RecipientTokenInvalid();
        }

        return $value;
    }

    private function intValue(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new RecipientTokenInvalid();
        }

        return (int) $value;
    }
}
