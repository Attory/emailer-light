<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

/** Server-session evidence only: no host, username or plaintext credential. */
final readonly class MailConnectionProof
{
    public function __construct(public string $actorId, public string $fingerprint, public int $expiresAt, public string $attempt)
    {
    }

    /** @return array{actor: string, fingerprint: string, expires: int, attempt: string} */
    public function sessionValue(): array
    {
        return ['actor' => $this->actorId, 'fingerprint' => $this->fingerprint, 'expires' => $this->expiresAt, 'attempt' => $this->attempt];
    }

    public static function fromSession(mixed $value): ?self
    {
        if (!\is_array($value) || !\is_string($value['attempt'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $value['attempt']) || !\is_string($value['actor'] ?? null) || !\is_string($value['fingerprint'] ?? null) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $value['fingerprint']) || !\is_int($value['expires'] ?? null) || $value['expires'] < time() || $value['expires'] > time() + 600) {
            return null;
        }

        return new self($value['actor'], $value['fingerprint'], $value['expires'], $value['attempt']);
    }
}
