<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Mail;

use App\Infrastructure\Mail\TestSmtpPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TestSmtpPolicyTest extends TestCase
{
    #[DataProvider('safeDsnProvider')]
    public function testItAllowsOnlyLocalMailpitAliases(string $dsn): void
    {
        TestSmtpPolicy::assertSafe($dsn);

        self::addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function safeDsnProvider(): iterable
    {
        yield 'Compose service' => ['smtp://mailpit:1025'];
        yield 'IPv4 loopback' => ['smtp://127.0.0.1:1025'];
        yield 'IPv6 loopback' => ['smtp://[::1]:1025'];
    }

    #[DataProvider('unsafeDsnProvider')]
    public function testItRejectsEveryOtherTransportShape(string $dsn): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('approved local Mailpit endpoint');

        TestSmtpPolicy::assertSafe($dsn);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeDsnProvider(): iterable
    {
        yield 'Missing' => [''];
        yield 'Null transport' => ['null://null'];
        yield 'Non-local host' => ['smtp://smtp.example.test:1025'];
        yield 'Wrong port' => ['smtp://mailpit:25'];
        yield 'Credentials' => ['smtp://user:placeholder@mailpit:1025'];
        yield 'Query options' => ['smtp://mailpit:1025?verify_peer=0'];
        yield 'Host suffix confusion' => ['smtp://mailpit.example.test:1025'];
    }
}
