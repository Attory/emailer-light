<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\Contract\SendingNotReady;
use App\Module\Delivery\Application\DeliveryScheduler;
use App\Module\Delivery\Presentation\Cli\RunSchedulerCommand;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SchedulerWaitingCommandTest extends TestCase
{
    public function testSupervisedWaitingNeverQueuesThroughAClosedSendingGate(): void
    {
        $command = $this->command();
        self::assertSame(0, $command->execute(['--wait-for-configuration' => true]));
        self::assertStringContainsString('scheduled=0 reconciled=0 status=waiting_for_readiness', $command->getDisplay());
    }

    public function testAnExplicitSchedulerRunStillReportsTheClosedGate(): void
    {
        $command = $this->command();
        $this->expectException(SendingNotReady::class);
        $command->execute([]);
    }

    public function testWaitingDoesNotHideDatabaseOrApplicationFailures(): void
    {
        $command = $this->command(true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('synthetic database failure');
        $command->execute(['--wait-for-configuration' => true]);
    }

    private function command(bool $broken = false): CommandTester
    {
        $connection = self::createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(1);
        if ($broken) {
            $connection->method('executeStatement')->willThrowException(new \RuntimeException('synthetic database failure'));
        }
        $gate = self::createStub(SendingGate::class);
        $gate->method('assertReady')->willThrowException(new SendingNotReady('SMTP is not configured.'));
        $gateway = self::createMock(CampaignDeliveryGateway::class);
        $gateway->expects(self::never())->method('materializeAndDispatch');
        $gateway->expects(self::never())->method('dispatchDue');
        $locks = new LockFactory(new FlockStore(sys_get_temp_dir()));
        $scheduler = new DeliveryScheduler($connection, $gateway, $locks, self::createStub(RecipientUnsubscribeLinks::class), $gate, self::createStub(InterruptedDeliveryRecovery::class), new MaintenanceOperationLock($locks));

        return new CommandTester(new RunSchedulerCommand($scheduler));
    }
}
