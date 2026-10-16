<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Preserve URL-encoded credentials when launching an authenticated older runtime. */
final class LegacyDatabaseEnvironment
{
    /** PHP proc_open drops explicit empty array values. Restore only empty values,
     * never credentials, through the distro environment launcher without a shell.
     *
     * @param list<string>          $command
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    public static function command(array $command, array $environment): array
    {
        $empty = [];
        foreach ($environment as $name => $value) {
            if ('' === $value) {
                if (1 !== preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name)) {
                    throw new \RuntimeException('Release environment name is unsafe.');
                }
                $empty[] = $name.'=';
            }
        }

        return [] === $empty ? $command : array_merge(['/usr/bin/env', '--'], $empty, $command);
    }

    /** @param array<string, string> $environment
     * @return array<string, string>
     */
    public static function forRelease(string $release, array $environment): array
    {
        $configuration = @file_get_contents($release.'/config/packages/doctrine.yaml');
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
