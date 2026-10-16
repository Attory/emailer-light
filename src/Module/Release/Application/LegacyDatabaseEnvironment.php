<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Preserve URL-encoded credentials when launching an authenticated older runtime. */
final class LegacyDatabaseEnvironment
{
    /** @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function forRelease(string $release, array $environment): array
    {
        $configuration = file_get_contents($release.'/config/packages/doctrine.yaml');
        if (false === $configuration) {
            throw new \RuntimeException('Authenticated release database configuration is unavailable.');
        }
        if (str_contains($configuration, '%env(resolve:DATABASE_URL)%') && isset($environment['DATABASE_URL'])) {
            // Symfony resolve consumes %% as one literal %. New literal-env
            // runtimes receive the original DSN without this legacy escaping.
            $environment['DATABASE_URL'] = str_replace('%', '%%', $environment['DATABASE_URL']);
        }

        return $environment;
    }
}
