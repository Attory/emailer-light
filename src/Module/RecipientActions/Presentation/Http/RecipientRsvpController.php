<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Presentation\Http;

use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RecipientRsvpController extends AbstractController
{
    private const TOKEN = 'v[1-9][0-9]{0,4}\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}';

    public function __construct(private readonly RecipientAnalyticsService $analytics)
    {
    }

    #[Route('/r/{token}/rsvp', name: 'recipient_rsvp', requirements: ['token' => self::TOKEN], methods: ['GET', 'HEAD', 'POST'])]
    public function rsvp(string $token, Request $request): Response
    {
        try {
            $submitted = false;
            if ($request->isMethod('POST')) {
                $keys = array_values(array_unique(array_keys($request->request->all())));
                sort($keys);
                if (2_048 < \strlen($request->getContent()) || [] !== $request->files->all() || ['guests', 'note', 'response'] !== $keys) {
                    throw new \InvalidArgumentException('RSVP request is invalid.');
                }
                $guests = $request->request->getString('guests');
                if (1 !== preg_match('/^(?:0|[1-9]|1[0-9]|20)$/D', $guests)) {
                    throw new \InvalidArgumentException('RSVP guest count is invalid.');
                }
                $this->analytics->submitRsvp($token, $request->request->getString('response'), (int) $guests, $request->request->getString('note'));
                $submitted = true;
            }
            $form = $this->analytics->rsvpForm($token);
            $response = $this->render('recipient/rsvp.html.twig', ['form' => $form, 'submitted' => $submitted]);
            if ('HEAD' === $request->getMethod()) {
                $response->setContent('');
            }
        } catch (\InvalidArgumentException) {
            $response = new Response('Request not accepted.', Response::HTTP_BAD_REQUEST);
        } catch (\Throwable) {
            $response = new Response('', Response::HTTP_NOT_FOUND);
        }

        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
