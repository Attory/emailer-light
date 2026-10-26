<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Configuration\Infrastructure\SmtpFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpFailureClassifierTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function failures(): iterable
    {
        yield 'DNS' => ['php_network_getaddresses: getaddrinfo failed', 'dns'];
        yield 'timeout' => ['Connection timed out', 'timeout'];
        yield 'refusal' => ['Connection refused', 'connection'];
        yield 'TLS word in hostname is not a certificate failure' => ['Connection to smtp.tls.example.test timed out', 'timeout'];
        yield 'AUTH word in hostname is not an authentication failure' => ['Connection to authenticator.example.test refused: connection refused', 'connection'];
        yield 'certificate' => ['Peer certificate CN did not match expected peer name', 'tls_certificate'];
        yield 'authentication' => ['Failed to authenticate using LOGIN; got code "535"', 'authentication'];
        yield 'internal' => ['Unexpected application failure', 'internal'];
    }

    #[DataProvider('failures')]
    public function testSafeCategorisationDoesNotRetainProviderDialogue(string $message, string $category): void
    {
        $secret = 'Synthetic SMTP private value';
        $failure = SmtpFailureClassifier::classify(new \RuntimeException($message.' '.$secret), 'connect_tls_auth');
        self::assertSame($category, $failure->category);
        self::assertStringNotContainsString($secret, $failure->getMessage());
        self::assertStringNotContainsString($secret, json_encode($failure->safeDiagnostics(), JSON_THROW_ON_ERROR));
        self::assertSame('connect_tls_auth', $failure->safeDiagnostics()['phase']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{16}$/', $failure->reference);
        self::assertStringContainsString('No email was sent.', $failure->getMessage());
    }
}
