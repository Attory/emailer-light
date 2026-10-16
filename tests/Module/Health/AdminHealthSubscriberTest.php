<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use App\Module\Health\Application\Doctor;
use App\Module\Health\Presentation\Http\AdminHealthSubscriber;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class AdminHealthSubscriberTest extends TestCase
{
    public function testMailSettingsRefreshesThePersistedFullReportBeforeBuildingTheHeader(): void
    {
        $connection = self::createMock(Connection::class);
        $recorded = false;
        $connection->expects(self::once())->method('insert')->with('health_check_run', self::callback(static function (array $report) use (&$recorded): bool {
            $recorded = true;

            return 'full' === $report['run_type'];
        }));
        $connection->expects(self::once())->method('fetchAllAssociative')->willReturnCallback(static function () use (&$recorded): array {
            self::assertTrue($recorded, 'The settings header must use the refreshed report.');

            return [];
        });
        $request = Request::create('/admin/settings/mail');
        $request->attributes->set('_route', 'admin_mail_settings');
        $event = new ControllerEvent(self::createStub(HttpKernelInterface::class), static fn (): null => null, $request, HttpKernelInterface::MAIN_REQUEST);
        (new AdminHealthSubscriber(new Doctor([], $connection)))->onController($event);
        self::assertSame([], $request->attributes->get('doctor_active_findings'));
    }
}
