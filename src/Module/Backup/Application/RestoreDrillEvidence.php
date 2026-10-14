<?php

declare(strict_types=1);

namespace App\Module\Backup\Application;

use App\Module\Secrets\Application\Contract\MasterKey;

/**
 * @phpstan-type DrillPayload array{format: string, backup_id: string, ciphertext_sha256: string, byte_size: int, source_version: string, schema_version: string, restored_at: string, checked_tables: int, checked_assets: int}
 */
final readonly class RestoreDrillEvidence
{
    private const FORMAT = 'emailer-light-restore-drill-v1';

    public function __construct(private MasterKey $masterKey)
    {
    }

    /** @param array<string, mixed> $payload */
    public function issue(array $payload): string
    {
        $payload = $this->validate($payload);

        return json_encode([...$payload, 'mac' => $this->mac($payload)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    /** @return DrillPayload */
    public function authenticate(string $contents): array
    {
        if (4096 < \strlen($contents)) {
            throw new \RuntimeException('Restore-drill evidence is invalid.');
        }
        try {
            $envelope = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Restore-drill evidence is invalid.');
        }
        if (!\is_array($envelope) || !\is_string($envelope['mac'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $envelope['mac'])) {
            throw new \RuntimeException('Restore-drill evidence is invalid.');
        }
        $mac = $envelope['mac'];
        unset($envelope['mac']);
        $payload = $this->validate($envelope);
        if (!hash_equals($this->mac($payload), $mac)) {
            throw new \RuntimeException('Restore-drill evidence authentication failed.');
        }

        return $payload;
    }

    /**
     * @param array<mixed> $payload
     *
     * @return DrillPayload
     */
    private function validate(array $payload): array
    {
        if (9 !== \count($payload)
            || self::FORMAT !== ($payload['format'] ?? null)
            || !\is_string($payload['backup_id'] ?? null) || 1 !== preg_match('/^[a-f0-9-]{36}$/D', $payload['backup_id'])
            || !\is_string($payload['ciphertext_sha256'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $payload['ciphertext_sha256'])
            || !\is_int($payload['byte_size'] ?? null) || 1 > $payload['byte_size']
            || !\is_string($payload['source_version'] ?? null) || 1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/D', $payload['source_version'])
            || !\is_string($payload['schema_version'] ?? null) || 1 !== preg_match('/^[0-9]{14}$/D', $payload['schema_version'])
            || !\is_string($payload['restored_at'] ?? null)
            || !\is_int($payload['checked_tables'] ?? null) || 1 > $payload['checked_tables']
            || !\is_int($payload['checked_assets'] ?? null) || 0 > $payload['checked_assets']
        ) {
            throw new \RuntimeException('Restore-drill evidence is invalid.');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $payload['restored_at'], new \DateTimeZone('UTC'));
        if (false === $time || $time->format('Y-m-d H:i:s.u') !== $payload['restored_at']) {
            throw new \RuntimeException('Restore-drill evidence is invalid.');
        }

        return [
            'format' => self::FORMAT,
            'backup_id' => $payload['backup_id'],
            'ciphertext_sha256' => $payload['ciphertext_sha256'],
            'byte_size' => $payload['byte_size'],
            'source_version' => $payload['source_version'],
            'schema_version' => $payload['schema_version'],
            'restored_at' => $payload['restored_at'],
            'checked_tables' => $payload['checked_tables'],
            'checked_assets' => $payload['checked_assets'],
        ];
    }

    /** @param DrillPayload $payload */
    private function mac(array $payload): string
    {
        $master = $this->masterKey->load();
        try {
            $key = hash_hkdf('sha256', $master, 32, 'emailer-light/restore-drill-receipt/v1');
            try {
                return hash_hmac('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $key);
            } finally {
                sodium_memzero($key);
            }
        } finally {
            sodium_memzero($master);
        }
    }
}
