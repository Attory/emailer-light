<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\MaintenanceOperationLock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class MaintenanceOperationLockTest extends TestCase
{
    public function testLockIsProcessReentrantButExcludesAnotherCoordinator(): void
    {
        $directory = sys_get_temp_dir().'/emailer-maintenance-lock-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        try {
            $factory = new LockFactory(new FlockStore($directory));
            $first = new MaintenanceOperationLock($factory);
            $second = new MaintenanceOperationLock($factory);
            $first->acquire();
            $first->acquire();
            try {
                $second->acquire();
                self::fail('A concurrent maintenance coordinator acquired the shared lock.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
            $first->release();
            try {
                $second->acquire();
                self::fail('A nested maintenance lock was released too early.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
            $first->release();
            $second->acquire();
            $second->release();
            self::addToAssertionCount(1);
        } finally {
            @rmdir($directory);
        }
    }
}
