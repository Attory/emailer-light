<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Presentation\Http;

use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final readonly class RecipientActionLimiterSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Target('recipient_action_source')]
        private RateLimiterFactoryInterface $sourceLimiter,
        #[Target('recipient_action_token')]
        private RateLimiterFactoryInterface $tokenLimiter,
        private string $key,
    ) {
        if (16 > \strlen($this->key)) {
            throw new \InvalidArgumentException('Recipient-action rate-limit key is unavailable.');
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['limit', 1_024]];
    }

    public function limit(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $path = rawurldecode($event->getRequest()->getPathInfo());
        if (1 !== preg_match('#^/r/([^/]+)/(?:unsubscribe|view|open\.gif|click/[0-9]+|rsvp)$#D', $path, $matches)) {
            return;
        }
        $source = $event->getRequest()->getClientIp() ?? 'unknown';
        $sourceKey = hash_hmac('sha256', "recipient-source\0".$source, $this->key);
        $tokenKey = hash_hmac('sha256', "recipient-token\0".$matches[1], $this->key);
        if ($this->sourceLimiter->create($sourceKey)->consume()->isAccepted() && $this->tokenLimiter->create($tokenKey)->consume()->isAccepted()) {
            return;
        }

        $event->setResponse(new Response('Request could not be processed.', Response::HTTP_TOO_MANY_REQUESTS, [
            'Cache-Control' => 'no-store, max-age=0',
            'Retry-After' => '60',
        ]));
    }
}
