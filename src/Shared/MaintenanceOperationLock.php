<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class MaintenanceOperationLock
{
    private int $depth = 0;
    private ?LockInterface $lock = null;

    public function __construct(private readonly LockFactory $locks)
    {
    }

    public function acquire(): void
    {
        if (!$this->tryAcquire()) {
            throw new \RuntimeException('Another installation-wide maintenance operation is active.');
        }
    }

    public function tryAcquire(): bool
    {
        if (0 < $this->depth) {
            ++$this->depth;

            return true;
        }
        $lock = $this->locks->createLock('emailer-maintenance-operation', 7200.0);
        if (!$lock->acquire()) {
            return false;
        }
        $this->lock = $lock;
        $this->depth = 1;

        return true;
    }

    public function release(): void
    {
        if (1 > $this->depth) {
            throw new \LogicException('Maintenance lock release is unbalanced.');
        }
        --$this->depth;
        if (0 === $this->depth) {
            $this->lock?->release();
            $this->lock = null;
        }
    }
}
