<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\Preflight;
use App\Module\Setup\Domain\PreflightCheck;

final readonly class SystemPreflight implements Preflight
{
    /** @param list<string> $requiredExtensions */
    public function __construct(
        private string $projectDirectory,
        private string $privateDirectory,
        private array $requiredExtensions = [
            'ctype',
            'curl',
            'filter',
            'hash',
            'intl',
            'json',
            'mbstring',
            'openssl',
            'pdo',
            'pdo_mysql',
            'session',
            'sodium',
            'zip',
        ],
    ) {
    }

    /** @return list<PreflightCheck> */
    public function run(?string $documentRoot = null): array
    {
        $checks = [];
        $runtimeVersion = phpversion();
        $checks[] = new PreflightCheck(
            'PHP runtime',
            version_compare($runtimeVersion, '8.4.0', '>=') && version_compare($runtimeVersion, '8.6.0', '<'),
            'PHP must be a project-supported 8.4 or 8.5 release.',
        );

        $missingExtensions = array_values(array_filter(
            $this->requiredExtensions,
            static fn (string $extension): bool => !\extension_loaded($extension),
        ));
        $checks[] = new PreflightCheck(
            'Required PHP extensions',
            [] === $missingExtensions,
            [] === $missingExtensions ? 'Required extensions are available.' : 'One or more required PHP extensions are unavailable.',
        );

        $expectedDocumentRoot = realpath($this->projectDirectory.'/public');
        $actualDocumentRoot = null === $documentRoot || '' === $documentRoot ? false : realpath($documentRoot);
        $checks[] = new PreflightCheck(
            'Public document root',
            false !== $expectedDocumentRoot && false !== $actualDocumentRoot && $expectedDocumentRoot === $actualDocumentRoot,
            'The web server document root must resolve exactly to public/.',
        );

        FilesystemChallengeStore::ensurePrivateDirectory($this->privateDirectory);
        $privateMetadata = @lstat($this->privateDirectory);
        $privateSafe = false !== $privateMetadata
            && is_dir($this->privateDirectory)
            && is_writable($this->privateDirectory)
            && 0 === ($privateMetadata['mode'] & 0o022);
        $checks[] = new PreflightCheck(
            'Private storage',
            $privateSafe,
            'Private storage must be writable and unavailable to group/other writers.',
        );

        $privatePath = realpath($this->privateDirectory);
        $publicPath = realpath($this->projectDirectory.'/public');
        $outsidePublic = false !== $privatePath && false !== $publicPath && !self::isWithin($privatePath, $publicPath);
        $checks[] = new PreflightCheck(
            'Public/private boundary',
            $outsidePublic,
            'Private storage must remain outside public/.',
        );

        $checks[] = new PreflightCheck(
            'MariaDB driver',
            \in_array('mysql', \PDO::getAvailableDrivers(), true),
            'The PDO MySQL driver must be available; credentials are collected only in Phase 3.',
        );
        $checks[] = new PreflightCheck(
            'CLI capability',
            PHP_SAPI === 'cli' || is_file($this->projectDirectory.'/bin/console'),
            'The Symfony CLI entry point must be available for worker and scheduler setup.',
        );

        return $checks;
    }

    private static function isWithin(string $candidate, string $directory): bool
    {
        $directory = rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($candidate.DIRECTORY_SEPARATOR, $directory);
    }
}
