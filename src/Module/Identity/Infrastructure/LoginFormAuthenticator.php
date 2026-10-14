<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\LoginThrottle;
use App\Module\Identity\Application\LoginThrottled;
use App\Module\Identity\Application\SessionRegistry;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\CustomCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

final class LoginFormAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly LoginThrottle $throttle,
        private readonly SessionRegistry $sessions,
        private readonly AuditSink $audit,
        private readonly Connection $connection,
        private readonly DoctrineUserProvider $users,
    ) {
    }

    public function supports(Request $request): bool
    {
        return 'identity_login' === $request->attributes->get('_route') && $request->isMethod('POST');
    }

    public function authenticate(Request $request): Passport
    {
        $login = mb_substr($request->request->getString('login_identifier'), 0, 254, 'UTF-8');
        $password = substr($request->request->getString('password'), 0, 1024);
        $source = $request->getClientIp() ?? 'unknown';
        try {
            $reservation = $this->throttle->reserveLogin($login, $source);
            $request->attributes->set('identity.login_reservation', $reservation);
        } catch (LoginThrottled) {
            $request->attributes->set('identity.throttle_blocked', true);
            throw new CustomUserMessageAuthenticationException('Authentication failed.');
        }

        return new Passport(
            new UserBadge($login, fn (string $identifier): AuthenticatedUser => $this->users->loadForAuthentication($identifier)),
            new CustomCredentials(
                function (mixed $credentials, UserInterface $user): bool {
                    if (!$user instanceof AuthenticatedUser
                        || !\is_array($credentials)
                        || !\is_string($credentials['password'] ?? null)
                    ) {
                        return false;
                    }
                    $passwordMatches = password_verify($credentials['password'], $user->getPassword());
                    $passwordValid = !hash_equals(DoctrineUserProvider::DUMMY_ID, $user->id()) && $passwordMatches;

                    return $passwordValid;
                },
                ['password' => $password],
            ),
            [new CsrfTokenBadge('identity-login', $request->request->getString('_token'))],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): Response
    {
        $user = $token->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw new \RuntimeException('Authenticated identity is unavailable.');
        }

        $source = $request->getClientIp() ?? 'unknown';
        $this->connection->transactional(function () use ($user, $source): void {
            $this->connection->update('app_user', ['last_login_at' => \App\Module\Identity\Application\IdentityUtil::now()], ['id' => $user->id()]);
            $this->audit->append(new AuditRecord(
                $user->id(),
                'authentication.login',
                $user->id(),
                'success',
                ['method' => 'password'],
                $this->throttle->sourceHash($source),
            ));
        });
        $reservation = $this->reservation($request->attributes->get('identity.login_reservation'));
        if (null !== $reservation) {
            $this->throttle->loginSucceeded($reservation);
        }
        $request->getSession()->remove('identity.last_login');
        $request->getSession()->migrate(true);
        $this->sessions->bind(
            $request->getSession()->getId(),
            $user->id(),
            $source,
            $request->headers->get('User-Agent'),
        );

        $target = $this->getTargetPath($request->getSession(), $firewallName);

        return new RedirectResponse($target ?? $this->urls->generate('admin_dashboard'), Response::HTTP_SEE_OTHER);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        if (true === $request->attributes->get('identity.throttle_blocked')) {
            return new Response('Too many requests.', Response::HTTP_TOO_MANY_REQUESTS, ['Cache-Control' => 'no-store']);
        }
        $login = mb_substr($request->request->getString('login_identifier'), 0, 254, 'UTF-8');
        $source = $request->getClientIp() ?? 'unknown';
        $request->getSession()->set('identity.last_login', mb_strtolower(trim($login), 'UTF-8'));
        $reservation = $this->reservation($request->attributes->get('identity.login_reservation'));
        $newlyLocked = false;
        if (null !== $reservation) {
            $newlyLocked = $this->throttle->loginFailed($reservation);
        }
        $this->audit->append(new AuditRecord(
            null,
            $newlyLocked ? 'authentication.lockout' : 'authentication.failure',
            null,
            'failure',
            ['reason' => 'generic'],
            $this->throttle->sourceHash($source),
        ));
        $request->getSession()->set('identity.login_error', true);

        return new RedirectResponse($this->getLoginUrl($request), Response::HTTP_SEE_OTHER);
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urls->generate('identity_login');
    }

    /** @return array<string, string>|null */
    private function reservation(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $reservation = [];
        foreach ($value as $hash => $type) {
            if (!\is_string($hash) || !\is_string($type)) {
                return null;
            }
            $reservation[$hash] = $type;
        }

        return [] === $reservation ? null : $reservation;
    }
}
