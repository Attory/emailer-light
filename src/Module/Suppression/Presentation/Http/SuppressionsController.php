<?php

declare(strict_types=1);

namespace App\Module\Suppression\Presentation\Http;

use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Suppression\Application\SuppressionManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/suppressions')]
final class SuppressionsController extends AbstractController
{
    public function __construct(private readonly SuppressionManager $suppressions)
    {
    }

    #[Route('', name: 'admin_suppressions', methods: ['GET', 'HEAD'])]
    public function suppressions(): Response
    {
        return $this->render('admin/suppressions.html.twig', ['suppressions' => $this->suppressions->list($this->user())]);
    }

    #[Route('/add', name: 'admin_suppression_add', methods: ['POST'])]
    public function add(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('suppression-add', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->suppressions->add($this->user(), $request->request->getString('email'), $request->request->getString('reason', 'manual'));
            $this->addFlash('success', 'Global suppression recorded.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Suppression was not accepted.');
        }

        return $this->redirectToRoute('admin_suppressions', status: Response::HTTP_SEE_OTHER);
    }

    private function user(): AdministrativeActor
    {
        $user = $this->getUser();
        if (!$user instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
