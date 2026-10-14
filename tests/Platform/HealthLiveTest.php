<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HealthLiveTest extends WebTestCase
{
    public function testLivenessResponseIsCoarseAndStartsNoSession(): void
    {
        $client = self::createClient();
        $client->request('GET', '/health/live');

        $response = $client->getResponse();

        self::assertResponseIsSuccessful();
        self::assertSame('{"status":"ok"}', $response->getContent());
        self::assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringNotContainsString('Symfony', $response->getContent());
    }

    public function testHeadRequestReturnsNoResponseBody(): void
    {
        $client = self::createClient();
        $client->request('HEAD', '/health/live');

        self::assertResponseIsSuccessful();
        self::assertSame('', $client->getResponse()->getContent());
    }
}
