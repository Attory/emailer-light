<?php

declare(strict_types=1);

namespace App\Module\Templates\Presentation\Http;

use App\Module\Templates\Application\Contract\TemplateAssetStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TemplateAssetController
{
    public function __construct(private TemplateAssetStorage $storage)
    {
    }

    #[Route('/template-assets/{filename}', name: 'template_asset_public', requirements: ['filename' => '[a-f0-9]{48}\.(?:jpg|png|webp)'], methods: ['GET', 'HEAD'])]
    public function asset(string $filename): Response
    {
        try {
            $response = new BinaryFileResponse($this->storage->publicPath($filename));
            $response->setPublic();
            $response->setMaxAge(31536000);
            $response->setImmutable();
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Content-Security-Policy', "default-src 'none'; sandbox");

            return $response;
        } catch (\Throwable) {
            return new Response('Not found.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'no-store']);
        }
    }
}
