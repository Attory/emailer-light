<?php

declare(strict_types=1);

namespace App\Module\Analytics\Presentation\Http;

use App\Module\Analytics\Application\RsvpManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/rsvp')]
final class RsvpController extends AbstractController
{
    public function __construct(private readonly RsvpManager $rsvp)
    {
    }

    #[Route('', name: 'admin_rsvp', methods: ['GET', 'HEAD'])]
    public function view(): Response
    {
        return $this->render('admin/rsvp.html.twig', $this->rsvp->view($this->actor()));
    }

    #[Route('/{campaign}/export', name: 'admin_rsvp_export', requirements: ['campaign' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function export(Request $request, string $campaign): Response
    {
        if (!$this->isCsrfTokenValid('rsvp-export-'.$campaign, $request->request->getString('_token'))) {
            return new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        }
        $stream = tmpfile();
        if (false === $stream) {
            throw new \RuntimeException('Export staging is unavailable.');
        }
        try {
            $this->rsvp->export($this->actor(), $campaign, $stream);
            if (0 !== fseek($stream, 0)) {
                throw new \RuntimeException('Export staging could not be read.');
            }
        } catch (\Throwable $exception) {
            fclose($stream);
            throw $exception;
        }

        return new StreamedResponse(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, Response::HTTP_OK, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="rsvp-'.$campaign.'.csv"', 'Cache-Control' => 'no-store']);
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
