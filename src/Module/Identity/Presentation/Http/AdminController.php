<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AdministratorPasswordManager;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\SessionRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/admin')]
final class AdminController extends AbstractController
{
    public function __construct(
        private readonly AdministratorPasswordManager $passwords,
        private readonly SessionRegistry $sessions,
        private readonly AuditSink $audit,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    #[Route('', name: 'admin_dashboard', methods: ['GET', 'HEAD'])]
    public function dashboard(): Response
    {
        return $this->render('admin/dashboard.html.twig', ['user' => $this->user()]);
    }

    #[Route('/logout', name: 'admin_logout', methods: ['POST'])]
    public function logout(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-logout')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->user();
        $this->audit->append(new AuditRecord($user->id(), 'authentication.logout', $user->id(), 'success'));
        $this->sessions->revoke($user->id(), $this->sessions->currentHash($request->getSession()->getId()));
        $this->tokens->setToken(null);
        $request->getSession()->invalidate();

        return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/profile', name: 'admin_profile', methods: ['GET', 'HEAD'])]
    public function profile(): Response
    {
        return $this->render('admin/profile.html.twig', ['user' => $this->user()]);
    }

    #[Route('/profile/password', name: 'admin_password_change', methods: ['POST'])]
    public function changePassword(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-password-change')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $password = $request->request->getString('new_password');
        if (!hash_equals($password, $request->request->getString('password_confirmation'))) {
            $this->addFlash('error', 'Password change was not accepted.');

            return $this->redirectToRoute('admin_profile', status: Response::HTTP_SEE_OTHER);
        }
        try {
            $updated = $this->passwords->change($this->user(), $request->request->getString('current_password'), $password, $request->getSession()->getId());
            $token = $this->tokens->getToken();
            if (null === $token) {
                throw $this->createAccessDeniedException();
            }
            $token->setUser($updated);
            $request->getSession()->migrate(true);
            $this->sessions->bind($request->getSession()->getId(), $updated->id(), $request->getClientIp(), $request->headers->get('User-Agent'));
            $this->addFlash('success', 'Password changed. Other sessions were revoked.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Password change was not accepted.');
        }

        return $this->redirectToRoute('admin_profile', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/sessions', name: 'admin_sessions', methods: ['GET', 'HEAD'])]
    public function sessions(Request $request): Response
    {
        $user = $this->user();

        return $this->render('admin/sessions.html.twig', ['sessions' => $this->sessions->listForUser($user->id(), $request->getSession()->getId())]);
    }

    #[Route('/sessions/revoke', name: 'admin_session_revoke', methods: ['POST'])]
    public function revokeSession(Request $request): Response
    {
        if (!$this->validCsrf($request, 'admin-session-revoke')) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $user = $this->user();
        $idHash = $request->request->getString('session');
        if (!password_verify($request->request->getString('current_password'), $user->getPassword())
            || 64 !== \strlen($idHash) || 1 !== preg_match('/^[a-f0-9]{64}$/', $idHash)
            || !$this->sessions->revoke($user->id(), $idHash)) {
            $this->addFlash('error', 'Session revocation was not accepted.');

            return $this->redirectToRoute('admin_sessions', status: Response::HTTP_SEE_OTHER);
        }
        $this->audit->append(new AuditRecord($user->id(), 'identity.session.revoked', $user->id(), 'success'));
        if (hash_equals($idHash, $this->sessions->currentHash($request->getSession()->getId()))) {
            $this->tokens->setToken(null);
            $request->getSession()->invalidate();

            return $this->redirectToRoute('identity_login', status: Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('admin_sessions', status: Response::HTTP_SEE_OTHER);
    }

    private function user(): AuthenticatedUser
    {
        $user = $this->getUser();
        if (!$user instanceof AuthenticatedUser) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function validCsrf(Request $request, string $id): bool
    {
        return $this->isCsrfTokenValid($id, $request->request->getString('_token'));
    }
}
