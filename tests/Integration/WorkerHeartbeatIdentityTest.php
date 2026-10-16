<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Delivery\Application\Contract\SendingNotReady;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\Delivery\Infrastructure\WorkerHeartbeatSubscriber;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WorkerHeartbeatIdentityTest extends KernelTestCase
{
    public function testPidReuseCannotPreserveThePreviousProcessKind(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $connection->beginTransaction();
        $connection->executeStatement('DELETE FROM worker_heartbeat');
        $hostname = gethostname();
        $id = substr((false === $hostname ? 'host' : $hostname).'-'.getmypid(), 0, 100);
        $now = Identity::now();
        try {
            $connection->insert('worker_heartbeat', ['worker_id' => $id, 'worker_kind' => 'scheduler', 'schema_version' => 1, 'started_at' => $now, 'heartbeat_at' => $now, 'stopped_at' => null]);
            $worker = new WorkerHeartbeatSubscriber($connection);
            $worker->started();
            self::assertSame('worker', $connection->fetchOne('SELECT worker_kind FROM worker_heartbeat WHERE worker_id=?', [$id]));
            $worker->stopped();
            self::assertNotNull($connection->fetchOne('SELECT stopped_at FROM worker_heartbeat WHERE worker_id=?', [$id]));
            $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            try {
                $gate = self::createMock(\App\Module\Delivery\Application\Contract\SendingGate::class);
                $gate->expects(self::once())->method('assertReady')->willThrowException(new SendingNotReady('Synthetic closed gate.'));
                $locks = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\InMemoryStore());
                $gateway = self::createMock(\App\Module\Delivery\Application\Contract\CampaignDeliveryGateway::class);
                $gateway->expects(self::never())->method('dispatchDue');
                $scheduler = new DeliveryScheduler($connection, $gateway, $locks, self::createStub(\App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks::class), $gate, self::createStub(\App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery::class), new \App\Shared\MaintenanceOperationLock($locks));
                $scheduler->run($id);
                self::fail('A quiesced fixture must retain its sending gate.');
            } catch (SendingNotReady) {
                self::assertSame('scheduler', $connection->fetchOne('SELECT worker_kind FROM worker_heartbeat WHERE worker_id=?', [$id]));
                self::assertNull($connection->fetchOne('SELECT stopped_at FROM worker_heartbeat WHERE worker_id=?', [$id]));
            }
        } finally {
            $connection->rollBack();
        }
    }
}
