<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Http;

use App\Module\Health\Application\Doctor;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

final readonly class AdminHealthSubscriber implements EventSubscriberInterface
{
    public function __construct(private Doctor $doctor)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            KernelEvents::CONTROLLER => 'onController',
        ];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        if ('main' !== $event->getFirewallName()) {
            return;
        }
        try {
            $this->doctor->runLightweightCached();
        } catch (\Throwable) {
            // Authentication must remain available when health evidence storage is impaired.
        }
    }

    public function onController(ControllerEvent $event): void
    {
        $route = $event->getRequest()->attributes->get('_route');
        if (!\is_string($route) || !str_starts_with($route, 'admin_')) {
            return;
        }
        try {
            // Settings actions redirect here. Refresh actual checks so the header does
            // not retain pre-configuration findings after a successful connection test.
            if (\in_array($route, ['admin_mail_settings'], true)) {
                $this->doctor->run();
            }
            $event->getRequest()->attributes->set('doctor_active_findings', $this->doctor->activeFindings());
        } catch (\Throwable) {
            $event->getRequest()->attributes->set('doctor_active_findings', [[
                'code' => 'doctor_state_unavailable',
                'severity' => 'critical',
                'summary' => 'Current operational health state is unavailable.',
                'sending_blocked' => 0,
                'acknowledged_at' => null,
            ]]);
        }
    }
}
