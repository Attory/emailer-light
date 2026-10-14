<?php

declare(strict_types=1);

namespace App\Module\Setup\Domain;

final readonly class ChallengeState
{
    public function __construct(
        public string $identifier,
        public string $codeHash,
        public int $createdAt,
        public int $expiresAt,
        public int $failedAttempts,
        public string $requestIpHash,
    ) {
        if ('' === $identifier || 64 !== \strlen($codeHash) || $expiresAt <= $createdAt || $failedAttempts < 0) {
            throw new \InvalidArgumentException('Invalid setup challenge state.');
        }
    }

    /** @return array{identifier: string, code_hash: string, created_at: int, expires_at: int, failed_attempts: int, request_ip_hash: string} */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'code_hash' => $this->codeHash,
            'created_at' => $this->createdAt,
            'expires_at' => $this->expiresAt,
            'failed_attempts' => $this->failedAttempts,
            'request_ip_hash' => $this->requestIpHash,
        ];
    }

    /** @param array<mixed, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['identifier', 'code_hash', 'created_at', 'expires_at', 'failed_attempts', 'request_ip_hash'] as $key) {
            if (!\array_key_exists($key, $data)) {
                throw new \InvalidArgumentException('Incomplete setup challenge state.');
            }
        }

        if (!\is_string($data['identifier']) || !\is_string($data['code_hash']) || !\is_int($data['created_at']) || !\is_int($data['expires_at']) || !\is_int($data['failed_attempts']) || !\is_string($data['request_ip_hash'])) {
            throw new \InvalidArgumentException('Malformed setup challenge state.');
        }

        return new self(
            $data['identifier'],
            $data['code_hash'],
            $data['created_at'],
            $data['expires_at'],
            $data['failed_attempts'],
            $data['request_ip_hash'],
        );
    }

    public function withFailedAttempt(): self
    {
        return new self(
            $this->identifier,
            $this->codeHash,
            $this->createdAt,
            $this->expiresAt,
            $this->failedAttempts + 1,
            $this->requestIpHash,
        );
    }
}
