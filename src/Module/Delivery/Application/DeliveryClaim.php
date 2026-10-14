<?php

declare(strict_types=1);

namespace App\Module\Delivery\Application;

final readonly class DeliveryClaim
{
    private function __construct(
        public ?int $delayMilliseconds,
        public ?string $recipientId,
        public ?string $email,
        public ?string $profileJson,
        public ?string $attemptId,
        public ?int $attemptNumber,
    ) {
    }

    public static function delayed(int $milliseconds): self
    {
        return new self($milliseconds, null, null, null, null, null);
    }

    public static function attempt(string $recipientId, string $email, string $profileJson, string $attemptId, int $attemptNumber): self
    {
        return new self(null, $recipientId, $email, $profileJson, $attemptId, $attemptNumber);
    }
}
