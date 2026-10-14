<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Http;

use App\Module\Setup\Application\Contract\InstallationLock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class SetupModeSubscriber implements EventSubscriberInterface
{
    public function __construct(private InstallationLock $installationLock)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['enforceSetupMode', 0]];
    }

    public function enforceSetupMode(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $route = $event->getRequest()->attributes->get('_route');

        if (!\is_string($route) || 'health_live' === $route) {
            return;
        }

        $isSetupRoute = str_starts_with($route, 'setup_');

        if ($this->installationLock->exists()) {
            if ($isSetupRoute) {
                $event->setResponse(new Response('', Response::HTTP_NOT_FOUND));
            }

            return;
        }

        if (!$isSetupRoute) {
            $event->setResponse(new JsonResponse(
                ['status' => 'setup_required'],
                Response::HTTP_SERVICE_UNAVAILABLE,
                [
                    'Cache-Control' => 'no-store, max-age=0',
                    'Retry-After' => '60',
                ],
            ));
        }
    }
}
