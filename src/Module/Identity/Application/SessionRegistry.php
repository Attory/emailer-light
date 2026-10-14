<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Doctrine\DBAL\Connection;

final readonly class SessionRegistry
{
    public function __construct(
        private Connection $connection,
        private SensitiveValueHasher $hasher,
    ) {
    }

    public function bind(string $sessionId, string $userId, ?string $source, ?string $userAgent): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $formattedNow = $now->format('Y-m-d H:i:s.u');
        $this->connection->executeStatement(<<<'SQL'
INSERT INTO user_session (
    id_hash, user_id, payload, created_at, last_seen_at, idle_expires_at, absolute_expires_at,
    revoked_at, source_hash, user_agent_hash
) VALUES (?, ?, '', ?, ?, ?, ?, NULL, ?, ?)
ON DUPLICATE KEY UPDATE
    user_id = IF(revoked_at IS NULL, VALUES(user_id), user_id),
    source_hash = IF(revoked_at IS NULL, VALUES(source_hash), source_hash),
    user_agent_hash = IF(revoked_at IS NULL, VALUES(user_agent_hash), user_agent_hash)
SQL, [
            $this->hashId($sessionId),
            $userId,
            $formattedNow,
            $formattedNow,
            $now->modify('+30 minutes')->format('Y-m-d H:i:s.u'),
            $now->modify('+12 hours')->format('Y-m-d H:i:s.u'),
            null === $source ? null : $this->hasher->hash('session-source', $source),
            null === $userAgent ? null : $this->hasher->hash('session-agent', substr($userAgent, 0, 1_024)),
        ]);
    }

    /** @return list<array{id_hash: string, created_at: string, last_seen_at: string, idle_expires_at: string, absolute_expires_at: string, current: bool}> */
    public function listForUser(string $userId, string $currentSessionId): array
    {
        $current = $this->hashId($currentSessionId);
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id_hash, created_at, last_seen_at, idle_expires_at, absolute_expires_at FROM user_session WHERE user_id = ? AND revoked_at IS NULL AND idle_expires_at > ? AND absolute_expires_at > ? ORDER BY last_seen_at DESC',
            [$userId, IdentityUtil::now(), IdentityUtil::now()],
        );

        $result = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id_hash'] ?? null)
                || !\is_string($row['created_at'] ?? null)
                || !\is_string($row['last_seen_at'] ?? null)
                || !\is_string($row['idle_expires_at'] ?? null)
                || !\is_string($row['absolute_expires_at'] ?? null)
            ) {
                continue;
            }
            $result[] = [
                'id_hash' => $row['id_hash'],
                'created_at' => $row['created_at'],
                'last_seen_at' => $row['last_seen_at'],
                'idle_expires_at' => $row['idle_expires_at'],
                'absolute_expires_at' => $row['absolute_expires_at'],
                'current' => hash_equals($current, $row['id_hash']),
            ];
        }

        return $result;
    }

    public function revoke(string $userId, string $idHash): bool
    {
        return 1 === $this->connection->executeStatement(
            "UPDATE user_session SET payload = '', revoked_at = ? WHERE id_hash = ? AND user_id = ? AND revoked_at IS NULL",
            [IdentityUtil::now(), $idHash, $userId],
        );
    }

    public function revokeAll(string $userId, ?string $exceptSessionId = null): int
    {
        $sql = "UPDATE user_session SET payload = '', revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL";
        $parameters = [IdentityUtil::now(), $userId];
        if (null !== $exceptSessionId) {
            $sql .= ' AND id_hash <> ?';
            $parameters[] = $this->hashId($exceptSessionId);
        }

        $affected = $this->connection->executeStatement($sql, $parameters);

        return is_numeric($affected) ? (int) $affected : 0;
    }

    public function currentHash(string $sessionId): string
    {
        return $this->hashId($sessionId);
    }

    private function hashId(string $sessionId): string
    {
        return $this->hasher->hash('session-id', $sessionId);
    }
}
