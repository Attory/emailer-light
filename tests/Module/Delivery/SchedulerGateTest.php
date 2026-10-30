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
    /** @return iterable<string, array{list<array<string, string>>, bool}> */
    public static function committedHandoffs(): iterable
    {
        yield 'host committed' => [[['kind' => 'host', 'phase' => 'handoff']], true];
        yield 'standalone native committed' => [[['kind' => 'native', 'phase' => 'handoff']], true];
        yield 'another core still mutable' => [[['kind' => 'host', 'phase' => 'handoff'], ['kind' => 'native', 'phase' => 'active']], false];
        yield 'no committed core' => [[['kind' => 'host', 'phase' => 'active']], false];
        yield 'only recovery' => [[['kind' => 'native', 'phase' => 'recovered']], false];
    }

    /** @param list<array<string, string>> $holds */
    #[\PHPUnit\Framework\Attributes\DataProvider('committedHandoffs')]
    public function testOnlyACommittedHandoffMayHeartbeatWithoutMutatingWork(array $holds, bool $allowed): void
    {
        $directory = sys_get_temp_dir().'/emailer-committed-handoff-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $blocker = null;
        try {
            $connection = $this->createMock(Connection::class);
            $connection->method('fetchOne')->willReturn(0);
            $connection->expects($allowed ? self::once() : self::never())->method('executeStatement')->with(self::callback(static fn (string $sql): bool => str_starts_with($sql, 'INSERT INTO worker_heartbeat')), self::anything())->willReturn(1);
            $recovery = $this->createMock(InterruptedDeliveryRecovery::class);
            $recovery->expects(self::never())->method('recover');
            $links = $this->createMock(RecipientUnsubscribeLinks::class);
            $links->expects(self::never())->method('purgeExpired');
            $gateway = $this->createMock(CampaignDeliveryGateway::class);
            $gateway->expects(self::never())->method('materializeAndDispatch');
            $gateway->expects(self::never())->method('dispatchDue');
            $control = self::createStub(\App\Module\Delivery\Application\Contract\DeliveryControl::class);
            $control->method('status')->willReturn(['configured' => true, 'owner' => 'enabled', 'generation' => 0, 'holds' => $holds]);
            $gate = new class implements SendingGate {
                public function assertReady(): void
                {
                    throw new \DomainException('held');
                }

                public function assertMaintenanceReady(): void
                {
                }
            };
            $locks = new LockFactory(new FlockStore($directory));
            $blocker = new MaintenanceOperationLock($locks);
            $blocker->acquire();
            $scheduler = new DeliveryScheduler($connection, $gateway, $locks, $links, $gate, $recovery, new MaintenanceOperationLock($locks), $control);
            $this->expectException(\DomainException::class);
            $scheduler->run('actual-command-test');
        } finally {
            $blocker?->release();
            foreach (false === ($paths = glob($directory.'/*')) ? [] : $paths as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

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
