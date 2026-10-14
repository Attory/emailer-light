<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Delivery\Application\Contract\DeliveryTransport;
use App\Module\Delivery\Application\Contract\SendingGate;
use App\Module\Delivery\Application\DeliverCampaignRecipientHandler;
use App\Module\Delivery\Application\DeliverCampaignTestHandler;
use App\Module\Delivery\Application\Message\DeliverCampaignRecipient;
use App\Module\Delivery\Application\Message\DeliverCampaignTest;
use App\Module\RecipientActions\Application\Contract\RecipientUnsubscribeLinks;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class SendingGateTest extends TestCase
{
    public function testCriticalGateDefersQueuedMessageBeforeDatabaseClaimOrTransport(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $transport = self::createMock(DeliveryTransport::class);
        $transport->expects(self::never())->method('send');
        $gateway = self::createMock(CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchAfter')->with('00000000-0000-7000-8000-000000000001', 1, 30_000);
        $gate = new class implements SendingGate {
            public function assertReady(): void
            {
                throw new \DomainException('critical readiness');
            }

            public function assertMaintenanceReady(): void
            {
            }
        };
        $handler = new DeliverCampaignRecipientHandler($connection, self::createStub(DeliveryConfiguration::class), self::createStub(SuppressionLookup::class), $transport, $gateway, self::createStub(RecipientUnsubscribeLinks::class), self::createStub(AuditSink::class), $gate);

        $handler(new DeliverCampaignRecipient('00000000-0000-7000-8000-000000000001', 1));
    }

    public function testCriticalGateDefersQueuedTestBeforeDatabaseClaimOrTransport(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $transport = self::createMock(DeliveryTransport::class);
        $transport->expects(self::never())->method('send');
        $gateway = self::createMock(CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchTestAfter')->with('00000000-0000-7000-8000-000000000002', 30_000);
        $gate = new class implements SendingGate {
            public function assertReady(): void
            {
                throw new \DomainException('critical readiness');
            }

            public function assertMaintenanceReady(): void
            {
            }
        };
        $handler = new DeliverCampaignTestHandler($connection, self::createStub(PublishedTemplateProvider::class), self::createStub(DeliveryConfiguration::class), $transport, self::createStub(SuppressionLookup::class), $gate, $gateway);

        $handler(new DeliverCampaignTest('00000000-0000-7000-8000-000000000002'));
    }
}
