<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class LivenessController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET', 'HEAD'], format: 'json')]
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(
            ['status' => 'ok'],
            Response::HTTP_OK,
            [
                'Cache-Control' => 'no-store, max-age=0',
                'Pragma' => 'no-cache',
            ],
        );
    }
}
