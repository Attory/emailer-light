<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use Doctrine\DBAL\Connection;

final readonly class CanonicalOrigin
{
    public function __construct(private Connection $connection)
    {
    }

    public function value(): string
    {
        $origin = $this->connection->fetchOne("SELECT canonical_base_url FROM organisation_settings WHERE singleton_key = '1'");
        if (!\is_string($origin)) {
            throw new \RuntimeException('Canonical application origin is unavailable.');
        }
        $parts = parse_url($origin);
        if (false === $parts
            || 'https' !== ($parts['scheme'] ?? null)
            || !\is_string($parts['host'] ?? null)
            || '' === $parts['host']
            || 1 === preg_match('/[\x00-\x20\x7F]/', $origin)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && !\in_array($parts['path'], ['', '/'], true))
        ) {
            throw new \RuntimeException('Canonical application origin is invalid.');
        }

        $port = isset($parts['port']) && 443 !== $parts['port'] ? ':'.$parts['port'] : '';

        return 'https://'.strtolower($parts['host']).$port;
    }

    public function absolutePath(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('Canonical application path is invalid.');
        }

        return $this->value().$path;
    }
}
