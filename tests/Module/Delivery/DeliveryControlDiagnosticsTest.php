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
    public function testProductionNeverTreatsMissingRequiredControlsAsLegacy(): void
    {
        $db = self::createStub(Connection::class);
        $db->method('fetchOne')->willReturn('01900000-0000-7000-8000-000000000001');
        $parent = sys_get_temp_dir().'/missing-control-'.bin2hex(random_bytes(8));
        mkdir($parent, 0o700);
        try {
            $control = new \App\Module\Delivery\Infrastructure\FilesystemDeliveryControl($db, $parent.'/private');
            $checks = [
                'status' => static function () use ($control): void { $control->status(); },
                'claimLease' => static function () use ($control): void { $control->claimLease(); },
                'pause' => static function () use ($control): void { $control->pause(); },
                'enable' => static function () use ($control): void { $control->enable(); },
            ];
            foreach ($checks as $action => $check) {
                try {
                    $check();
                    self::fail('Missing production controls must block '.$action);
                } catch (DeliveryControlUnavailable $error) {
                    self::assertStringContainsString('delivery_control_unavailable', $error->getMessage());
                }
            }
        } finally {
            rmdir($parent);
        }
    }

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
