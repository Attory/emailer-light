<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Module\Secrets\Application\SecretCipher;
use App\Module\Secrets\Application\SecretContext;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSecretVault implements SecretVault
{
    public function __construct(
        private Connection $connection,
        private MasterKey $masterKey,
        private SecretCipher $cipher,
    ) {
    }

    public function store(string $recordType, string $plaintext, string $installationId): string
    {
        if ('' === $plaintext || 64 < \strlen($recordType) || 1 !== preg_match('/^[a-z][a-z0-9._-]+$/', $recordType)) {
            throw new \InvalidArgumentException('Secret record is invalid.');
        }

        $recordId = self::uuidV7();
        $context = new SecretContext($installationId, $recordType, $recordId);
        $key = $this->masterKey->load();

        try {
            $envelope = $this->cipher->encrypt($plaintext, $key, $context);
        } finally {
            sodium_memzero($key);
        }

        $this->connection->insert('encrypted_secret', [
            'id' => $recordId,
            'record_type' => $recordType,
            'envelope' => $envelope,
            'key_version' => SecretCipher::KEY_VERSION,
            'schema_version' => SecretContext::SCHEMA_VERSION,
            'created_at' => self::now(),
            'rotated_at' => null,
        ]);

        return $recordId;
    }

    public function replace(string $recordId, string $recordType, string $plaintext, string $installationId): void
    {
        if ('' === $plaintext) {
            throw new \InvalidArgumentException('Secret record is invalid.');
        }

        $existingType = $this->connection->fetchOne('SELECT record_type FROM encrypted_secret WHERE id = ?', [$recordId]);
        if ($recordType !== $existingType) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }

        $key = $this->masterKey->load();
        try {
            $envelope = $this->cipher->encrypt($plaintext, $key, new SecretContext($installationId, $recordType, $recordId));
        } finally {
            sodium_memzero($key);
        }

        if (1 !== $this->connection->update('encrypted_secret', [
            'envelope' => $envelope,
            'key_version' => SecretCipher::KEY_VERSION,
            'schema_version' => SecretContext::SCHEMA_VERSION,
            'rotated_at' => self::now(),
        ], ['id' => $recordId])) {
            throw new \RuntimeException('Encrypted secret replacement failed.');
        }
    }

    public function reveal(string $recordId, string $recordType, string $installationId): string
    {
        $record = $this->connection->fetchAssociative(
            'SELECT envelope, key_version, schema_version, record_type FROM encrypted_secret WHERE id = ?',
            [$recordId],
        );

        if (false === $record
            || $recordType !== $record['record_type']
            || !is_numeric($record['key_version'])
            || !is_numeric($record['schema_version'])
            || SecretCipher::KEY_VERSION !== (int) $record['key_version']
            || SecretContext::SCHEMA_VERSION !== (int) $record['schema_version']
            || !\is_string($record['envelope'])
        ) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }

        $key = $this->masterKey->load();
        try {
            return $this->cipher->decrypt($record['envelope'], $key, new SecretContext(
                $installationId,
                $recordType,
                $recordId,
                (int) $record['schema_version'],
            ));
        } finally {
            sodium_memzero($key);
        }
    }

    public static function uuidV7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);
        for ($index = 5; 0 <= $index; --$index) {
            $bytes[$index] = \chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s.u');
    }
}
