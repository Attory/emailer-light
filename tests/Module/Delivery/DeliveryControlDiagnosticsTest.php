<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Delivery\Application\Contract\DeliveryControl;
use App\Module\Delivery\Infrastructure\DeliveryReadinessProbe;
use App\Shared\DeliveryControlUnavailable;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DeliveryControlDiagnosticsTest extends TestCase
{
    public function testUnavailableControlsAreASpecificCriticalSendingRequirement(): void
    {
        $db = self::createMock(Connection::class);
        $db->expects(self::never())->method('fetchAssociative');
        $control = self::createStub(DeliveryControl::class);
        $control->method('status')->willThrowException(new DeliveryControlUnavailable());
        $probe = new DeliveryReadinessProbe($db, 'prod', control: $control);
        $findings = $probe->findings();
        self::assertCount(1, $findings);
        self::assertSame('delivery_control_unavailable', $findings[0]['code']);
        self::assertSame('critical', $findings[0]['severity']);
        self::assertStringContainsString('sending is blocked', $findings[0]['summary']);
    }
}
