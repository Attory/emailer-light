<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup;

use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Presentation\Http\SetupModeSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class SetupModeSubscriberTest extends TestCase
{
    public function testUnlockedInstallationBlocksOrdinaryRoutesButAllowsHealth(): void
    {
        $subscriber = new SetupModeSubscriber(new MemoryInstallationLock(false));

        $ordinaryEvent = $this->eventForRoute('future_admin_route');
        $subscriber->enforceSetupMode($ordinaryEvent);
        $ordinaryResponse = $ordinaryEvent->getResponse();
        self::assertNotNull($ordinaryResponse);
        self::assertSame(503, $ordinaryResponse->getStatusCode());
        self::assertSame('{"status":"setup_required"}', $ordinaryResponse->getContent());

        $healthEvent = $this->eventForRoute('health_live');
        $subscriber->enforceSetupMode($healthEvent);
        self::assertNull($healthEvent->getResponse());
    }

    public function testLockedInstallationHidesEverySetupRoute(): void
    {
        $subscriber = new SetupModeSubscriber(new MemoryInstallationLock(true));
        $event = $this->eventForRoute('setup_index');

        $subscriber->enforceSetupMode($event);

        $response = $event->getResponse();
        self::assertNotNull($response);
        self::assertSame(404, $response->getStatusCode());
        self::assertSame('', $response->getContent());
    }

    private function eventForRoute(string $route): RequestEvent
    {
        $request = Request::create('/');
        $request->attributes->set('_route', $route);

        return new RequestEvent(
            self::createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

final class MemoryInstallationLock implements InstallationLock
{
    public function __construct(private bool $locked)
    {
    }

    public function exists(): bool
    {
        return $this->locked;
    }

    public function create(array $metadata): void
    {
        $this->locked = true;
    }

    public function remove(): void
    {
        $this->locked = false;
    }
}
