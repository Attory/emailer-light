<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Presentation\Http;

use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RecipientAnalyticsController
{
    private const TOKEN = 'v[1-9][0-9]{0,4}\.[A-Za-z0-9_-]{43}\.[A-Za-z0-9_-]{43}';
    private const GIF = "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;";

    public function __construct(private RecipientAnalyticsService $analytics)
    {
    }

    #[Route('/r/{token}/view', name: 'recipient_view', requirements: ['token' => self::TOKEN], methods: ['GET', 'HEAD'])]
    public function view(string $token, Request $request): Response
    {
        try {
            $html = $this->analytics->browserContent($token);
            $response = new Response('HEAD' === $request->getMethod() ? '' : $html, Response::HTTP_OK, ['Content-Type' => 'text/html; charset=UTF-8']);
        } catch (\Throwable) {
            $response = new Response('', Response::HTTP_NOT_FOUND);
        }

        return $this->privateResponse($response);
    }

    #[Route('/r/{token}/open.gif', name: 'recipient_open', requirements: ['token' => self::TOKEN], methods: ['GET', 'HEAD'])]
    public function open(string $token, Request $request): Response
    {
        try {
            if ('HEAD' !== $request->getMethod()) {
                $this->analytics->recordOpen($token);
            }
        } catch (\Throwable) {
            // A fixed response prevents token probing through image behavior.
        }

        return $this->privateResponse(new Response('HEAD' === $request->getMethod() ? '' : self::GIF, Response::HTTP_OK, ['Content-Type' => 'image/gif']));
    }

    #[Route('/r/{token}/click/{link}', name: 'recipient_click', requirements: ['token' => self::TOKEN, 'link' => '0|[1-9][0-9]{0,4}'], methods: ['GET', 'HEAD'])]
    public function click(string $token, int $link, Request $request): Response
    {
        try {
            $target = $this->analytics->clickTarget($token, $link, 'HEAD' !== $request->getMethod());

            return $this->privateResponse(new Response('', Response::HTTP_FOUND, ['Location' => $target]));
        } catch (\Throwable) {
            return $this->privateResponse(new Response('', Response::HTTP_NOT_FOUND));
        }
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->remove('Set-Cookie');

        return $response;
    }
}
