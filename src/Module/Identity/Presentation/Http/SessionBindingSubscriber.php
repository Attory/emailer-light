<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\SessionRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final readonly class SessionBindingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private TokenStorageInterface $tokens,
        private SessionRegistry $sessions,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['bind', -100]];
    }

    public function bind(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$event->getRequest()->hasSession()) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof AuthenticatedUser) {
            return;
        }
        $session = $event->getRequest()->getSession();
        if (!$session->isStarted()) {
            return;
        }
        $this->sessions->bind(
            $session->getId(),
            $user->id(),
            $event->getRequest()->getClientIp(),
            $event->getRequest()->headers->get('User-Agent'),
        );
    }
}
