<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Presentation\Http;

use App\Module\RecipientActions\Application\RecipientTokenInvalid;
use App\Module\RecipientActions\Application\RecipientTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class UnsubscribeController extends AbstractController
{
    public function __construct(private readonly RecipientTokenService $tokens)
    {
    }

    #[Route('/r/{token}/unsubscribe', name: 'recipient_unsubscribe', requirements: ['token' => 'v[1-9][0-9]{0,4}\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}'], methods: ['GET', 'POST'])]
    public function unsubscribe(Request $request, string $token): Response
    {
        try {
            if ($request->isMethod('GET')) {
                $this->tokens->inspect($token);

                return $this->publicResponse($this->renderView('recipient/unsubscribe.html.twig', ['completed' => false]));
            }

            $contentType = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));
            if ('application/x-www-form-urlencoded' !== $contentType) {
                return $this->publicResponse('Request could not be processed.', Response::HTTP_BAD_REQUEST);
            }
            $parameters = $request->request->all();
            $oneClick = ['List-Unsubscribe' => 'One-Click'] === $parameters;
            $manual = ['confirm' => 'unsubscribe'] === $parameters;
            if (!$oneClick && !$manual) {
                return $this->publicResponse('Request could not be processed.', Response::HTTP_BAD_REQUEST);
            }
            $this->tokens->unsubscribe($token);

            return $this->publicResponse($this->renderView('recipient/unsubscribe.html.twig', ['completed' => true]));
        } catch (RecipientTokenInvalid) {
            return $this->publicResponse('Request could not be processed.', Response::HTTP_NOT_FOUND);
        } catch (\Throwable) {
            return $this->publicResponse('Request could not be processed.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    private function publicResponse(string $body, int $status = Response::HTTP_OK): Response
    {
        return new Response($body, $status, [
            'Cache-Control' => 'no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }
}
