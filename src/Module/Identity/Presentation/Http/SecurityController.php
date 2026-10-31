<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    #[Route('/', name: 'identity_home', methods: ['GET', 'HEAD'])]
    public function home(): Response
    {
        return $this->redirectToRoute(null === $this->getUser() ? 'identity_login' : 'admin_dashboard', status: Response::HTTP_SEE_OTHER);
    }

    #[Route('/login', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        $target = $request->query->getString('return');
        // Only local admin pages may become a post-login continuation.
        if (('/admin' === $target || str_starts_with($target, '/admin/'))
            && !str_contains($target, '\\') && 0 === preg_match('/[[:cntrl:][:space:]]/', $target)
            && !str_contains($target, '/update-status') && !str_ends_with((string) parse_url($target, PHP_URL_PATH), '/progress')) {
            $request->getSession()->set('_security.main.target_path', $target);
        }
        if (null !== $this->getUser()) {
            $savedTarget = $request->getSession()->get('_security.main.target_path');

            return \is_string($savedTarget) ? $this->redirect($savedTarget, Response::HTTP_SEE_OTHER) : $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
        }
        $expiry = $request->query->getString('expiry', $request->attributes->getString('identity.session_expiry'));
        if (\in_array($expiry, ['idle', 'absolute'], true)) {
            $request->getSession()->set('identity.expiry_notice', $expiry);
        }

        return $this->render('identity/login.html.twig', ['expiry_notice' => $request->getSession()->get('identity.expiry_notice'), 'last_login' => $request->getSession()->get('identity.last_login', ''), 'error' => $request->getSession()->remove('identity.login_error')]);
    }
}
