<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class LockedSetupLifecycle implements SetupLifecycle
{
    public function __construct(
        private Connection $connection,
        private InstallationLock $installationLock,
        private LockFactory $lockFactory,
    ) {
    }

    public function mutate(\Closure $operation): mixed
    {
        return $this->synchronized(function () use ($operation): mixed {
            $this->assertOpen();

            return $operation();
        });
    }

    public function finalize(\Closure $operation): mixed
    {
        return $this->synchronized($operation);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $operation
     *
     * @return T
     */
    private function synchronized(\Closure $operation): mixed
    {
        $lock = $this->lockFactory->createLock('emailer-light.setup-lifecycle', 30.0, true);
        if (!$lock->acquire(true)) {
            throw new \RuntimeException('Setup lifecycle is already in progress.');
        }

        try {
            return $operation();
        } finally {
            $lock->release();
        }
    }

    private function assertOpen(): void
    {
        if ($this->installationLock->exists()) {
            throw new \RuntimeException('Installation setup is permanently locked.');
        }

        if (!$this->connection->createSchemaManager()->tablesExist(['installation_state'])) {
            return;
        }

        $completedAt = $this->connection->fetchOne("SELECT completed_at FROM installation_state WHERE singleton_key = '1'");
        if (false !== $completedAt && null !== $completedAt) {
            throw new \RuntimeException('Installation setup is permanently locked.');
        }
    }
}
