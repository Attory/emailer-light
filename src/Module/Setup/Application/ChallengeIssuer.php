<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Setup\Application\Contract\ChallengeStore;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\RandomBytes;
use App\Module\Setup\Domain\ChallengeState;
use App\Module\Setup\Domain\IssuedChallenge;
use Symfony\Component\Lock\LockFactory;

final readonly class ChallengeIssuer
{
    private const LIFETIME_SECONDS = 900;
    private const MAX_ATTEMPTS = 10;

    public function __construct(
        private ChallengeStore $store,
        private Clock $clock,
        private RandomBytes $random,
        private LockFactory $lockFactory,
    ) {
    }

    public function issue(string $requestIp): IssuedChallenge
    {
        $lock = $this->lockFactory->createLock('emailer-setup-proof', 10.0);

        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Setup challenge is temporarily unavailable.');
        }

        try {
            $now = $this->clock->now();
            $activeState = $this->store->load();

            if (
                null !== $activeState
                && $activeState->expiresAt > $now
                && $activeState->failedAttempts < self::MAX_ATTEMPTS
            ) {
                throw new ActiveChallengeExists($activeState->identifier);
            }

            if (null !== $activeState) {
                $this->store->delete();
            }

            $identifier = bin2hex($this->random->generate(16));
            $normalizedCode = ChallengeCode::encode($this->random->generate(20));
            $state = new ChallengeState(
                $identifier,
                hash('sha256', $normalizedCode),
                $now,
                $now + self::LIFETIME_SECONDS,
                0,
                self::requestIpHash($identifier, $requestIp),
            );
            $this->store->save($state);

            return new IssuedChallenge($identifier, ChallengeCode::display($normalizedCode), $state->expiresAt);
        } finally {
            $lock->release();
        }
    }

    public static function requestIpHash(string $identifier, string $requestIp): string
    {
        return hash('sha256', $identifier."\0".$requestIp);
    }
}
