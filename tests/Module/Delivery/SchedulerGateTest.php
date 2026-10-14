<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SchedulerGateTest extends TestCase
{
    public function testQuiescedRuntimeDoesNotRecoverDuringActiveMaintenance(): void
    {
        $directory = sys_get_temp_dir().'/emailer-scheduler-maintenance-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $blocker = null;
        try {
            $state = (object) ['recovered' => false, 'wrote' => false];
            $connection = self::createStub(Connection::class);
            $connection->method('fetchOne')->willReturn(0);
            $connection->method('executeStatement')->willReturnCallback(static function () use ($state): int {
                $state->wrote = true;

                return 1;
            });
            $recovery = self::createStub(InterruptedDeliveryRecovery::class);
            $recovery->method('recover')->willReturnCallback(static function () use ($state): void {
                $state->recovered = true;
            });
            $gate = new class($state) implements SendingGate {
                public function __construct(private \stdClass $state)
                {
                }

                public function assertReady(): void
                {
                    TestCase::assertFalse($this->state->recovered);
                    TestCase::assertFalse($this->state->wrote);
                    throw new \DomainException('delivery quiesced');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $locks = new LockFactory(new FlockStore($directory));
            $blocker = new MaintenanceOperationLock($locks);
            $blocker->acquire();
            $scheduler = new DeliveryScheduler($connection, self::createStub(CampaignDeliveryGateway::class), $locks, self::createStub(RecipientUnsubscribeLinks::class), $gate, $recovery, new MaintenanceOperationLock($locks));

            $this->expectException(\DomainException::class);
            $scheduler->run('scheduler-test');
        } finally {
            $blocker?->release();
            @rmdir($directory);
        }
    }

    public function testQuiescedRuntimeAllowsRecoveryBeforeMaintenanceStarts(): void
    {
        $directory = sys_get_temp_dir().'/emailer-scheduler-quiesced-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        try {
            $state = (object) ['recovered' => false, 'purged' => false];
            $connection = self::createStub(Connection::class);
            $connection->method('fetchOne')->willReturn(0);
            $recovery = self::createStub(InterruptedDeliveryRecovery::class);
            $recovery->method('recover')->willReturnCallback(static function () use ($state): void {
                $state->recovered = true;
            });
            $links = self::createStub(RecipientUnsubscribeLinks::class);
            $links->method('purgeExpired')->willReturnCallback(static function () use ($state): int {
                $state->purged = true;

                return 0;
            });
            $gate = new class($state) implements SendingGate {
                public function __construct(private \stdClass $state)
                {
                }

                public function assertReady(): void
                {
                    TestCase::assertTrue($this->state->recovered);
                    TestCase::assertFalse($this->state->purged);
                    throw new \DomainException('delivery quiesced');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $locks = new LockFactory(new FlockStore($directory));
            $scheduler = new DeliveryScheduler($connection, self::createStub(CampaignDeliveryGateway::class), $locks, $links, $gate, $recovery, new MaintenanceOperationLock($locks));

            $this->expectException(\DomainException::class);
            $scheduler->run('scheduler-test');
        } finally {
            @rmdir($directory);
        }
    }

    public function testCriticalSendingGateStillAllowsInterruptedWorkRecoveryBeforeBlockingNewWork(): void
    {
        $directory = sys_get_temp_dir().'/emailer-scheduler-gate-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        try {
            $state = (object) ['recovered' => false, 'purged' => false];
            $connection = self::createStub(Connection::class);
            $connection->method('fetchOne')->willReturn(1);
            $recovery = self::createStub(InterruptedDeliveryRecovery::class);
            $recovery->method('recover')->willReturnCallback(static function () use ($state): void {
                $state->recovered = true;
            });
            $links = self::createStub(RecipientUnsubscribeLinks::class);
            $links->method('purgeExpired')->willReturnCallback(static function () use ($state): int {
                $state->purged = true;

                return 0;
            });
            $gate = new class($state) implements SendingGate {
                public function __construct(private \stdClass $state)
                {
                }

                public function assertReady(): void
                {
                    TestCase::assertTrue($this->state->recovered);
                    TestCase::assertTrue($this->state->purged);
                    throw new \DomainException('critical readiness');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $locks = new LockFactory(new FlockStore($directory));
            $scheduler = new DeliveryScheduler($connection, self::createStub(CampaignDeliveryGateway::class), $locks, $links, $gate, $recovery, new MaintenanceOperationLock($locks));

            $this->expectException(\DomainException::class);
            $scheduler->run('scheduler-test');
        } finally {
            @rmdir($directory);
        }
    }
}
