<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Http;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'identity_login', methods: ['GET', 'POST'])]
    public function login(Request $request): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('admin_dashboard', status: Response::HTTP_SEE_OTHER);
        }

        return $this->render('identity/login.html.twig', ['last_login' => $request->getSession()->get('identity.last_login', ''), 'error' => $request->getSession()->remove('identity.login_error')]);
    }
}
