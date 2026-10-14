<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;

final readonly class LoginThrottle
{
    private const PENDING_SECONDS = 120;

    public function __construct(
        private Connection $connection,
        private SensitiveValueHasher $hasher,
    ) {
    }

    /** @return array<string, string> */
    public function reserveLogin(string $login, string $source): array
    {
        return $this->reserve([
            [$this->accountHash($login), 'account', 5],
            [$this->sourceHash($source), 'source', 20],
        ]);
    }

    /** @param array<string, string> $reservations */
    public function loginFailed(array $reservations): bool
    {
        return $this->finalizeFailure($reservations);
    }

    /** @param array<string, string> $reservations */
    public function loginSucceeded(array $reservations): void
    {
        $this->release($reservations, ['account']);
    }

    public function sourceHash(string $source): string
    {
        return $this->hasher->hash('login-source', $source);
    }

    public function accountHash(string $login): string
    {
        return $this->hasher->hash('login-account', mb_strtolower(trim($login), 'UTF-8'));
    }

    /**
     * @param list<array{string, string, int}> $scopes
     *
     * @return array<string, string>
     */
    private function reserve(array $scopes): array
    {
        usort($scopes, static fn (array $left, array $right): int => $left[0] <=> $right[0]);
        $reservations = $this->transactionalWithRetry(function (Connection $connection) use ($scopes): array {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $formattedNow = $now->format('Y-m-d H:i:s.u');
            foreach ($scopes as [$hash, $type]) {
                $connection->executeStatement(<<<'SQL'
INSERT INTO login_throttle (
    scope_hash, scope_type, failure_count, pending_count, pending_updated_at,
    first_failed_at, last_failed_at, not_before, locked_until
) VALUES (?, ?, 0, 0, NULL, ?, ?, ?, NULL)
ON DUPLICATE KEY UPDATE scope_hash = VALUES(scope_hash)
SQL, [$hash, $type, $formattedNow, $formattedNow, $formattedNow]);
            }

            $reservations = [];
            foreach ($scopes as [$hash, $type, $threshold]) {
                $record = $connection->fetchAssociative(
                    'SELECT failure_count, pending_count, pending_updated_at, first_failed_at, last_failed_at, not_before, locked_until FROM login_throttle WHERE scope_hash = ? FOR UPDATE',
                    [$hash],
                );
                if (false === $record) {
                    throw new \RuntimeException('Authentication throttle state is unavailable.');
                }
                $failureCount = is_numeric($record['failure_count'] ?? null) ? (int) $record['failure_count'] : 0;
                $pendingCount = is_numeric($record['pending_count'] ?? null) ? (int) $record['pending_count'] : 0;
                if (0 < $pendingCount && $this->stalePending($record['pending_updated_at'] ?? null, $now)) {
                    $pendingCount = 0;
                }
                $expired = 0 < $failureCount && $this->expiredWindow($record['last_failed_at'] ?? null, $now);
                if ($expired) {
                    $failureCount = 0;
                }
                $lockedUntil = $record['locked_until'] ?? null;
                $lockActive = $this->after($lockedUntil, $now);
                if (!$expired && 0 < $failureCount
                    && ($this->after($record['not_before'] ?? null, $now) || $lockActive)
                ) {
                    throw new LoginThrottled('Authentication is temporarily unavailable.');
                }
                if (!$lockActive && \is_string($lockedUntil) && '' !== $lockedUntil) {
                    $expired = true;
                    $failureCount = 0;
                }
                if ($failureCount + $pendingCount >= $threshold) {
                    throw new LoginThrottled('Authentication is temporarily unavailable.');
                }

                $connection->update('login_throttle', [
                    'scope_type' => $type,
                    'failure_count' => $failureCount,
                    'pending_count' => $pendingCount + 1,
                    'pending_updated_at' => 0 === $pendingCount ? $formattedNow : $record['pending_updated_at'],
                    'first_failed_at' => $expired ? $formattedNow : $record['first_failed_at'],
                    'last_failed_at' => $expired ? $formattedNow : $record['last_failed_at'],
                    'not_before' => $expired ? $formattedNow : $record['not_before'],
                    'locked_until' => $expired ? null : $record['locked_until'],
                ], ['scope_hash' => $hash]);
                $reservations[$hash] = $type;
            }

            return $reservations;
        });
        $this->purgeStaleRows();

        return $reservations;
    }

    /** @param array<string, string> $reservations */
    private function finalizeFailure(array $reservations): bool
    {
        ksort($reservations);

        return $this->transactionalWithRetry(function (Connection $connection) use ($reservations): bool {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $formattedNow = $now->format('Y-m-d H:i:s.u');
            $newlyLocked = false;
            foreach ($reservations as $hash => $type) {
                [$threshold, $lockSeconds] = $this->failurePolicy($type);
                $record = $connection->fetchAssociative(
                    'SELECT failure_count, pending_count, pending_updated_at, first_failed_at FROM login_throttle WHERE scope_hash = ? FOR UPDATE',
                    [$hash],
                );
                if (false === $record || !is_numeric($record['pending_count'] ?? null) || 1 > (int) $record['pending_count']) {
                    throw new \RuntimeException('Authentication reservation is unavailable.');
                }
                $failureCount = (is_numeric($record['failure_count'] ?? null) ? (int) $record['failure_count'] : 0) + 1;
                $newlyLocked = $newlyLocked || $failureCount === $threshold;
                $pendingCount = (int) $record['pending_count'] - 1;
                $delay = min(8, max(1, 2 ** min($failureCount - 1, 3)));
                $connection->update('login_throttle', [
                    'scope_type' => $type,
                    'failure_count' => $failureCount,
                    'pending_count' => $pendingCount,
                    'pending_updated_at' => 0 < $pendingCount ? $record['pending_updated_at'] ?? $formattedNow : null,
                    'first_failed_at' => 1 === $failureCount || !\is_string($record['first_failed_at'] ?? null)
                        ? $formattedNow
                        : $record['first_failed_at'],
                    'last_failed_at' => $formattedNow,
                    'not_before' => $now->modify('+'.$delay.' seconds')->format('Y-m-d H:i:s.u'),
                    'locked_until' => $failureCount >= $threshold ? $now->modify('+'.$lockSeconds.' seconds')->format('Y-m-d H:i:s.u') : null,
                ], ['scope_hash' => $hash]);
            }

            return $newlyLocked;
        });
    }

    /**
     * @param array<string, string> $reservations
     * @param list<string>          $clearFailureTypes
     */
    private function release(array $reservations, array $clearFailureTypes): void
    {
        ksort($reservations);
        $this->transactionalWithRetry(function (Connection $connection) use ($reservations, $clearFailureTypes): void {
            $now = IdentityUtil::now();
            foreach ($reservations as $hash => $type) {
                $record = $connection->fetchAssociative(
                    'SELECT failure_count, pending_count, pending_updated_at FROM login_throttle WHERE scope_hash = ? FOR UPDATE',
                    [$hash],
                );
                if (false === $record || !is_numeric($record['pending_count'] ?? null) || 1 > (int) $record['pending_count']) {
                    continue;
                }
                $pendingCount = (int) $record['pending_count'] - 1;
                $failureCount = is_numeric($record['failure_count'] ?? null) ? (int) $record['failure_count'] : 0;
                if (\in_array($type, $clearFailureTypes, true)) {
                    $failureCount = 0;
                }
                if (0 === $pendingCount && 0 === $failureCount) {
                    $connection->delete('login_throttle', ['scope_hash' => $hash]);
                    continue;
                }
                $values = [
                    'pending_count' => $pendingCount,
                    'pending_updated_at' => 0 < $pendingCount ? $record['pending_updated_at'] : null,
                ];
                if (\in_array($type, $clearFailureTypes, true)) {
                    $values += [
                        'failure_count' => 0,
                        'first_failed_at' => $now,
                        'last_failed_at' => $now,
                        'not_before' => $now,
                        'locked_until' => null,
                    ];
                }
                $connection->update('login_throttle', $values, ['scope_hash' => $hash]);
            }
        });
    }

    /** @return array{int, int} */
    private function failurePolicy(string $type): array
    {
        return match ($type) {
            'account' => [5, 300],
            'source' => [20, 900],
            default => throw new \InvalidArgumentException('Authentication throttle scope is invalid.'),
        };
    }

    /**
     * @template T
     *
     * @param \Closure(Connection): T $operation
     *
     * @return T
     */
    private function transactionalWithRetry(\Closure $operation): mixed
    {
        for ($attempt = 0;; ++$attempt) {
            try {
                return $this->connection->transactional($operation);
            } catch (RetryableException $exception) {
                if (2 <= $attempt) {
                    throw $exception;
                }
                usleep(5_000 * (2 ** $attempt));
            }
        }
    }

    private function after(mixed $value, \DateTimeImmutable $now): bool
    {
        if (!\is_string($value) || '' === $value) {
            return false;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) > $now;
        } catch (\Throwable) {
            return true;
        }
    }

    private function expiredWindow(mixed $value, \DateTimeImmutable $now): bool
    {
        if (!\is_string($value)) {
            return true;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) <= $now->modify('-15 minutes');
        } catch (\Throwable) {
            return true;
        }
    }

    private function stalePending(mixed $value, \DateTimeImmutable $now): bool
    {
        if (null === $value) {
            return true;
        }
        if (!\is_string($value)) {
            return true;
        }
        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC')) <= $now->modify('-'.self::PENDING_SECONDS.' seconds');
        } catch (\Throwable) {
            return true;
        }
    }

    private function purgeStaleRows(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            $this->connection->executeStatement(
                'DELETE FROM login_throttle WHERE pending_count = 0 AND last_failed_at <= ? AND (locked_until IS NULL OR locked_until <= ?) LIMIT 100',
                [$now->modify('-1 day')->format('Y-m-d H:i:s.u'), $now->format('Y-m-d H:i:s.u')],
            );
        } catch (RetryableException) {
            // Cleanup is bounded and opportunistic; reservation locking remains fail-closed.
        }
    }
}
