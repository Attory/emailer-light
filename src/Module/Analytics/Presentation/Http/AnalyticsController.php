<?php

declare(strict_types=1);

namespace App\Module\Analytics\Presentation\Http;

use App\Module\Analytics\Application\AnalyticsManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/analytics')]
final class AnalyticsController extends AbstractController
{
    public function __construct(private readonly AnalyticsManager $analytics)
    {
    }

    #[Route('', name: 'admin_analytics', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/analytics.html.twig', $this->analytics->view($this->actor()));
    }

    #[Route('/policy', name: 'admin_analytics_policy', methods: ['POST'])]
    public function policy(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('analytics-policy', $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        try {
            $this->analytics->configure($this->actor(), $request->request->getBoolean('open_tracking'), $request->request->getBoolean('click_tracking'), $request->request->getInt('retention_days'), $request->request->getBoolean('rsvp_enabled'), $request->request->getInt('rsvp_retention_days'), $request->request->getString('rsvp_repeat_policy'));
            $this->addFlash('success', 'Privacy policy updated for future campaign approvals.');
        } catch (\Throwable) {
            $this->addFlash('error', 'Tracking policy was not accepted.');
        }

        return $this->redirectToRoute('admin_analytics', status: Response::HTTP_SEE_OTHER);
    }

    private function actor(): AdministrativeActor
    {
        $actor = $this->getUser();
        if (!$actor instanceof AdministrativeActor) {
            throw $this->createAccessDeniedException();
        }

        return $actor;
    }
}
