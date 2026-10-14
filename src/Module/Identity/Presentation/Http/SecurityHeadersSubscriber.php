<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'harden'];
    }

    public function harden(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $response->headers->set('Content-Security-Policy', "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $route = $request->attributes->get('_route');
        if (\is_string($route) && (str_starts_with($route, 'admin_') || str_starts_with($route, 'identity_'))) {
            $response->headers->set('Cache-Control', 'no-store, max-age=0, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }
        $path = rawurldecode($request->getPathInfo());
        if (('/r' === $path || str_starts_with($path, '/r/')) || (\is_string($route) && str_starts_with($route, 'recipient_'))) {
            $response->headers->set('Cache-Control', 'no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }
    }
}
