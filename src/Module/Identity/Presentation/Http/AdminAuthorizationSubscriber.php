<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\LoginThrottle;
use App\Module\Identity\Application\RoutePolicyRegistry;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final readonly class AdminAuthorizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RoutePolicyRegistry $policies,
        private AuthorizationCheckerInterface $authorization,
        private AuditSink $audit,
        private TokenStorageInterface $tokens,
        private LoginThrottle $throttle,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => 'authorize'];
    }

    public function authorize(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = $event->getRequest()->attributes->get('_route');
        if (!str_starts_with(rawurldecode($event->getRequest()->getPathInfo()), '/admin')) {
            return;
        }

        $methods = \is_string($route) ? $this->policies->methods($route) : null;
        if (null === $methods
            || !\in_array($event->getRequest()->getMethod(), $methods, true)
            || !$this->authorization->isGranted('ROLE_ADMIN')
        ) {
            $user = $this->tokens->getToken()?->getUser();
            $this->audit->append(new AuditRecord(
                $user instanceof AuthenticatedUser ? $user->id() : null,
                'authorization.denied',
                null,
                'denied',
                ['operation' => \is_string($route) ? substr(preg_replace('/[^a-z0-9_.-]/', '_', strtolower($route)) ?? 'unknown', 0, 64) : 'unknown'],
                $this->throttle->sourceHash($event->getRequest()->getClientIp() ?? 'unknown'),
            ));
            throw new AccessDeniedException('Access denied.');
        }
    }
}
