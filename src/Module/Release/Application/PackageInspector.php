<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

final readonly class PackageInspector
{
    public function __construct(private string $publicKeyFile, private string $currentVersion)
    {
    }

    /** @return array<string, mixed> */
    public function inspect(string $archive): array
    {
        return $this->inspectPackage($archive, false);
    }

    /** @return array<string, mixed> */
    public function inspectInstalledSource(string $archive): array
    {
        return $this->inspectPackage($archive, true);
    }

    /** @return array<string, mixed> */
    private function inspectPackage(string $archive, bool $installedSource): array
    {
        $this->assertRegularFile($archive);
        $manifestFile = $archive.'.manifest.json';
        $checksumFile = $archive.'.sha256';
        $sbomFile = $archive.'.spdx.json';
        $signatureFile = $archive.'.sig';
        foreach ([$manifestFile, $checksumFile, $sbomFile, $signatureFile, $this->publicKeyFile] as $file) {
            $this->assertRegularFile($file);
        }
        $manifestBytes = $this->readBounded($manifestFile, 1048576);
        $checksumBytes = $this->readBounded($checksumFile, 256);
        $sbomBytes = $this->readBounded($sbomFile, 8388608);
        $signatureBytes = trim($this->readBounded($signatureFile, 256));
        $publicKeyBytes = trim($this->readBounded($this->publicKeyFile, 256, true));
        $manifest = json_decode($manifestBytes, true, 32, JSON_THROW_ON_ERROR);
        $sbom = json_decode($sbomBytes, true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($manifest) || 'emailer-light-release-v1' !== ($manifest['format'] ?? null) || !\is_array($manifest['files'] ?? null) || !\is_array($sbom) || 'SPDX-2.3' !== ($sbom['spdxVersion'] ?? null)) {
            throw new \RuntimeException('Release metadata is incompatible.');
        }
        $manifest = $this->associative($manifest);
        $files = $manifest['files'] ?? null;
        if (!\is_array($files)) {
            throw new \RuntimeException('Release file manifest is invalid.');
        }
        foreach (['version', 'source_commit', 'minimum_source_version', 'minimum_schema_version', 'schema_version'] as $field) {
            if (!\is_string($manifest[$field] ?? null) || '' === $manifest[$field]) {
                throw new \RuntimeException('Release manifest is incomplete.');
            }
        }
        $identifier = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
        $semanticVersion = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-'.$identifier.'(?:\.'.$identifier.')*)?$/D';
        $supportedSources = $manifest['supported_source_versions'] ?? null;
        $legacyInstalledSource = $installedSource && null === $supportedSources;
        if ((!$installedSource && (!\is_array($supportedSources) || [] === $supportedSources || false === array_is_list($supportedSources)))
            || ($installedSource && null !== $supportedSources && (!\is_array($supportedSources) || [] === $supportedSources || false === array_is_list($supportedSources)))
        ) {
            throw new \RuntimeException('Release source compatibility check failed.');
        }
        $supportedSourceVersions = [];
        foreach (\is_array($supportedSources) ? $supportedSources : [] as $supportedSource) {
            if (!\is_string($supportedSource) || 1 !== preg_match($semanticVersion, $supportedSource)) {
                throw new \RuntimeException('Release source compatibility check failed.');
            }
            $supportedSourceVersions[] = $supportedSource;
        }
        $selfInspection = hash_equals($manifest['version'], $this->currentVersion);
        if (1 !== preg_match($semanticVersion, $manifest['version'])
            || 1 !== preg_match($semanticVersion, $manifest['minimum_source_version'])
            || 1 !== preg_match($semanticVersion, $this->currentVersion)
            || \count($supportedSourceVersions) !== \count(array_unique($supportedSourceVersions, SORT_STRING))
            || (!$installedSource && !\in_array($manifest['minimum_source_version'], $supportedSourceVersions, true))
            || ($installedSource && !$legacyInstalledSource && !\in_array($manifest['minimum_source_version'], $supportedSourceVersions, true))
            || (!$installedSource && !$selfInspection && !\in_array($this->currentVersion, $supportedSourceVersions, true))
            || ($installedSource && !$selfInspection)
            || 1 !== preg_match('/^[a-f0-9]{40}$/', $manifest['source_commit'])
            || version_compare($manifest['minimum_source_version'], $this->currentVersion, '>')
            || basename($archive) !== 'emailer-light-'.$manifest['version'].'.zip'
        ) {
            throw new \RuntimeException('Release source compatibility check failed.');
        }
        if (1 !== preg_match('/^[0-9]{14}$/', $manifest['minimum_schema_version']) || 1 !== preg_match('/^[0-9]{14}$/', $manifest['schema_version']) || $manifest['schema_version'] < $manifest['minimum_schema_version']) {
            throw new \RuntimeException('Release schema metadata is invalid.');
        }
        if (!\is_bool($manifest['rollback_schema_compatible'] ?? null)) {
            throw new \RuntimeException('Release rollback compatibility declaration is invalid.');
        }
        foreach (['bin/console', 'composer.lock', 'config/services.yaml', 'public/index.php'] as $requiredRuntimeFile) {
            if (!\is_string($files[$requiredRuntimeFile] ?? null)) {
                throw new \RuntimeException('Release manifest omits a required runtime file.');
            }
        }
        $platform = $manifest['platform'] ?? null;
        $mariaDb = \is_array($platform) ? ($platform['mariadb'] ?? null) : null;
        if (!\is_array($platform) || !\is_string($platform['php_min'] ?? null) || !\is_string($platform['php_max_exclusive'] ?? null) || !\is_array($mariaDb) || ['10.11', '11.8'] !== array_values($mariaDb) || version_compare(PHP_VERSION, $platform['php_min'], '<') || !version_compare(PHP_VERSION, $platform['php_max_exclusive'], '<')) {
            throw new \RuntimeException('Release PHP compatibility check failed.');
        }
        $this->assertSbom($sbom, $manifest, $legacyInstalledSource);
        $archiveHash = hash_file('sha256', $archive);
        $expectedLine = $archiveHash.'  '.basename($archive)."\n";
        if (false === $archiveHash || !hash_equals($expectedLine, $checksumBytes)) {
            throw new \RuntimeException('Release checksum verification failed.');
        }
        try {
            $signature = sodium_base642bin($signatureBytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $publicKey = sodium_base642bin($publicKeyBytes, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException $exception) {
            throw new \RuntimeException('Release signature encoding is invalid.', previous: $exception);
        }
        $signed = $manifestBytes.$checksumBytes.hash('sha256', $sbomBytes);
        if (SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES !== \strlen($publicKey) || SODIUM_CRYPTO_SIGN_BYTES !== \strlen($signature) || !sodium_crypto_sign_verify_detached($signature, $signed, $publicKey)) {
            throw new \RuntimeException('Release signature verification failed.');
        }
        $zip = new \ZipArchive();
        if (true !== $zip->open($archive)) {
            throw new \RuntimeException('Release archive cannot be opened.');
        }
        try {
            $seen = [];
            $totalSize = 0;
            $servicesBytes = null;
            if ($zip->numFiles > 100000) {
                throw new \RuntimeException('Release archive contains too many entries.');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (false === $name || !$this->safeEntry($name) || isset($seen[$name])) {
                    throw new \RuntimeException('Release archive contains an unsafe path.');
                }
                $operations = 0;
                $attributes = 0;
                if ($zip->getExternalAttributesIndex($index, $operations, $attributes) && \is_int($attributes) && 0o120000 === (($attributes >> 16) & 0o170000)) {
                    throw new \RuntimeException('Release archive contains a symbolic link.');
                }
                $seen[$name] = true;
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $stat = $zip->statIndex($index);
                if (false === $stat || $stat['size'] < 0 || $stat['size'] > 536870912 || $totalSize > 2147483648 - $stat['size']) {
                    throw new \RuntimeException('Release archive expansion exceeds its safe bound.');
                }
                $totalSize += $stat['size'];
                $expected = $files[$name] ?? null;
                $stream = $zip->getStream($name);
                if (!\is_string($expected) || false === $stream) {
                    throw new \RuntimeException('Release archive differs from its manifest.');
                }
                $hash = hash_init('sha256');
                if ('config/services.yaml' === $name) {
                    if (1048576 < $stat['size']) {
                        fclose($stream);
                        throw new \RuntimeException('Release runtime configuration is too large.');
                    }
                    $servicesBytes = stream_get_contents($stream, 1048577);
                    if (false === $servicesBytes || \strlen($servicesBytes) !== $stat['size'] || !feof($stream)) {
                        fclose($stream);
                        throw new \RuntimeException('Release runtime configuration cannot be read safely.');
                    }
                    hash_update($hash, $servicesBytes);
                } else {
                    hash_update_stream($hash, $stream);
                }
                fclose($stream);
                if (!hash_equals($expected, hash_final($hash))) {
                    throw new \RuntimeException('Release file digest verification failed.');
                }
            }
            foreach (array_keys($files) as $name) {
                if (!\is_string($name) || !isset($seen[$name])) {
                    throw new \RuntimeException('Release manifest names a missing file.');
                }
            }
            $versionMatches = [];
            if (!\is_string($servicesBytes) || 1 !== preg_match_all('/^\s*emailer\.app_version:\s*[\'\"]([^\'\"]+)[\'\"]\s*$/m', $servicesBytes, $versionMatches) || 1 !== \count($versionMatches[1]) || $manifest['version'] !== $versionMatches[1][0]) {
                throw new \RuntimeException('Release runtime version differs from its manifest.');
            }
        } finally {
            $zip->close();
        }

        $manifest['_verified_archive_sha256'] = $archiveHash;
        $manifest['_verified_uncompressed_bytes'] = $totalSize;

        return $manifest;
    }

    /** @param array<mixed> $sbom
     * @param array<string, mixed> $manifest
     */
    private function assertSbom(array $sbom, array $manifest, bool $allowLegacyLicenseDecision): void
    {
        $version = $manifest['version'] ?? null;
        $source = $manifest['source_commit'] ?? null;
        if (!\is_string($version) || !\is_string($source) || 'SPDX-2.3' !== ($sbom['spdxVersion'] ?? null) || 'CC0-1.0' !== ($sbom['dataLicense'] ?? null) || 'SPDXRef-DOCUMENT' !== ($sbom['SPDXID'] ?? null) || 'Emailer Light '.$version !== ($sbom['name'] ?? null) || 'https://attory.example.test/spdx/emailer-light/'.$source.'/'.$version !== ($sbom['documentNamespace'] ?? null) || !\is_array($sbom['packages'] ?? null) || [] === $sbom['packages']) {
            throw new \RuntimeException('Release SBOM is incomplete or mismatched.');
        }
        $seen = [];
        foreach ($sbom['packages'] as $package) {
            if (!\is_array($package) || !\is_string($package['SPDXID'] ?? null) || '' === trim($package['SPDXID']) || !\is_string($package['name'] ?? null) || '' === trim($package['name']) || !\is_string($package['versionInfo'] ?? null) || '' === trim($package['versionInfo']) || !\is_string($package['licenseDeclared'] ?? null) || '' === trim($package['licenseDeclared']) || (!$allowLegacyLicenseDecision && 'NOASSERTION' === $package['licenseDeclared']) || isset($seen[$package['SPDXID']])) {
                throw new \RuntimeException('Release SBOM package metadata is invalid.');
            }
            $seen[$package['SPDXID']] = true;
        }
    }

    private function safeEntry(string $name): bool
    {
        return '' !== $name && !str_starts_with($name, '/') && !str_contains($name, "\0") && 1 !== preg_match('~(^|/)\.\.(/|$)~', $name) && 1 !== preg_match('~^[A-Za-z]:~', $name);
    }

    private function assertRegularFile(string $path): void
    {
        $stat = @lstat($path);
        if ('' === $path || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink']) {
            throw new \RuntimeException('Release input is missing or unsafe.');
        }
    }

    private function readBounded(string $path, int $limit, bool $rejectGroupOrWorldWrite = false): string
    {
        $pathStat = @lstat($path);
        $handle = @fopen($path, 'rb');
        if (!\is_array($pathStat) || false === $handle) {
            throw new \RuntimeException('Release metadata cannot be opened safely.');
        }
        try {
            $handleStat = fstat($handle);
            if (!\is_array($handleStat) || $pathStat['dev'] !== $handleStat['dev'] || $pathStat['ino'] !== $handleStat['ino'] || 0o100000 !== ($handleStat['mode'] & 0o170000) || 1 !== $handleStat['nlink'] || 1 > $handleStat['size'] || $handleStat['size'] > $limit) {
                throw new \RuntimeException('Release metadata size or identity is invalid.');
            }
            if ($rejectGroupOrWorldWrite && 0 !== ($handleStat['mode'] & 0o022)) {
                throw new \RuntimeException('Pinned release public key permissions are unsafe.');
            }
            $contents = stream_get_contents($handle, $limit + 1);
            if (false === $contents || \strlen($contents) !== $handleStat['size'] || !feof($handle)) {
                throw new \RuntimeException('Release metadata cannot be read safely.');
            }
        } finally {
            fclose($handle);
        }

        return $contents;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private function associative(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Release metadata keys are invalid.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
