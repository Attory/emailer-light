<?php

declare(strict_types=1);

namespace App\Module\Operations\Presentation\Http;

use App\Module\Operations\Application\OperationsDashboard;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class OperationsDashboardSubscriber implements EventSubscriberInterface
{
    public function __construct(private OperationsDashboard $dashboard)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'provide'];
    }

    public function provide(ControllerEvent $event): void
    {
        if ('admin_dashboard' === $event->getRequest()->attributes->get('_route')) {
            $event->getRequest()->attributes->set('operations_dashboard', $this->dashboard->sections());
        }
    }
}
