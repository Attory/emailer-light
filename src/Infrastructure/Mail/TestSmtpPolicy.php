<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

final class TestSmtpPolicy
{
    /**
     * @var list<string>
     */
    private const ALLOWED_HOSTS = ['mailpit', '127.0.0.1', '::1', '[::1]'];

    public static function assertSafe(string $dsn): void
    {
        $parts = parse_url($dsn);

        if (
            false === $parts
            || 'smtp' !== ($parts['scheme'] ?? null)
            || !\in_array($parts['host'] ?? '', self::ALLOWED_HOSTS, true)
            || 1025 !== ($parts['port'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && '' !== $parts['path'])
        ) {
            throw new \RuntimeException('The test mail transport is not the approved local Mailpit endpoint.');
        }
    }
}
