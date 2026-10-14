<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Identity\Application\LoginThrottle;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class IdentityRequestLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoginThrottle $throttle,
        #[Target('identity_page')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['limit', 1_024]];
    }

    public function limit(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isIdentityPath(rawurldecode($event->getRequest()->getPathInfo()))) {
            return;
        }
        $source = $event->getRequest()->getClientIp() ?? 'unknown';
        if ($this->limiter->create($this->throttle->sourceHash($source))->consume()->isAccepted()) {
            return;
        }

        $event->setResponse(new Response('Too many requests.', Response::HTTP_TOO_MANY_REQUESTS, [
            'Cache-Control' => 'no-store',
        ]));
    }

    private function isIdentityPath(string $path): bool
    {
        return '/login' === $path
            || '/password-reset' === $path
            || str_starts_with($path, '/password-reset/');
    }
}
