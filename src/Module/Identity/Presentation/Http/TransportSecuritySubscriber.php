<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Identity\Application\CanonicalOrigin;
use App\Module\Setup\Application\Contract\InstallationLock;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class TransportSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private InstallationLock $installationLock,
        private CanonicalOrigin $canonicalOrigin,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['enforce', 2_048]];
    }

    public function enforce(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->installationLock->exists()) {
            return;
        }
        $request = $event->getRequest();
        if (!$this->isProtectedPath(rawurldecode($request->getPathInfo()))) {
            return;
        }
        $canonicalOrigin = $this->canonicalOrigin->value();
        $requestOrigin = rtrim($request->getSchemeAndHttpHost(), '/');
        if ($request->isSecure() && hash_equals($canonicalOrigin, $requestOrigin)) {
            return;
        }
        if ($request->isMethodSafe()) {
            $target = $canonicalOrigin.$request->getPathInfo();
            $response = new RedirectResponse($target, Response::HTTP_PERMANENTLY_REDIRECT);
            $response->headers->set('Cache-Control', 'no-store');
            $event->setResponse($response);

            return;
        }

        $event->setResponse(new Response('Secure transport is required.', Response::HTTP_BAD_REQUEST, [
            'Cache-Control' => 'no-store',
        ]));
    }

    private function isProtectedPath(string $path): bool
    {
        return '/login' === $path
            || '/password-reset' === $path
            || str_starts_with($path, '/password-reset/')
            || '/admin' === $path
            || str_starts_with($path, '/admin/')
            || '/r' === $path
            || str_starts_with($path, '/r/');
    }
}
