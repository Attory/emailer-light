<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$options = getopt('', ['artifact:', 'output:', 'version:', 'source:', 'minimum-source:', 'supported-sources::', 'minimum-schema::', 'schema:', 'rollback-schema-compatible:', 'signing-key:', 'source-date-epoch:']);
$required = ['artifact', 'output', 'version', 'source', 'minimum-source', 'schema', 'rollback-schema-compatible', 'signing-key', 'source-date-epoch'];
foreach ($required as $name) {
    if (!isset($options[$name]) || !is_string($options[$name]) || '' === $options[$name]) {
        fwrite(STDERR, "Missing required option --$name.\n");
        exit(2);
    }
}
$identifier = '(?:0|[1-9][0-9]*|[0-9]*[A-Za-z-][0-9A-Za-z-]*)';
$semanticVersion = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-'.$identifier.'(?:\.'.$identifier.')*)?$/D';
$minimumSchema = $options['minimum-schema'] ?? $options['schema'];
$supportedSources = isset($options['supported-sources']) && is_string($options['supported-sources']) ? explode(',', $options['supported-sources']) : [$options['minimum-source']];
if (1 !== preg_match($semanticVersion, $options['version']) || 1 !== preg_match($semanticVersion, $options['minimum-source']) || [] === $supportedSources || count($supportedSources) !== count(array_unique($supportedSources)) || !in_array($options['minimum-source'], $supportedSources, true) || [] !== array_filter($supportedSources, static fn (string $version): bool => 1 !== preg_match($semanticVersion, $version) || version_compare($version, $options['version'], '>')) || 1 !== preg_match('/^[a-f0-9]{40}$/', $options['source']) || !is_string($minimumSchema) || 1 !== preg_match('/^[0-9]{14}$/', $minimumSchema) || 1 !== preg_match('/^[0-9]{14}$/', $options['schema']) || $minimumSchema > $options['schema'] || !in_array($options['rollback-schema-compatible'], ['true', 'false'], true) || !ctype_digit($options['source-date-epoch'])) {
    fwrite(STDERR, "Release metadata is invalid.\n");
    exit(2);
}
$artifact = realpath($options['artifact']);
$outputDirectory = realpath($options['output']);
$keyStat = @lstat($options['signing-key']);
$keyHandle = @fopen($options['signing-key'], 'rb');
$openedKeyStat = false === $keyHandle ? false : fstat($keyHandle);
if (false === $artifact || !is_dir($artifact) || false === $outputDirectory || !is_dir($outputDirectory) || !is_array($keyStat) || !is_array($openedKeyStat) || $keyStat['dev'] !== $openedKeyStat['dev'] || $keyStat['ino'] !== $openedKeyStat['ino'] || 0o100000 !== ($keyStat['mode'] & 0o170000) || 1 !== $keyStat['nlink'] || 0 !== ($keyStat['mode'] & 0o077) || $keyStat['size'] > 256) {
    if (is_resource($keyHandle)) {
        fclose($keyHandle);
    }
    fwrite(STDERR, "Release input path or signing-key permissions are unsafe.\n");
    exit(2);
}
$secretEncoded = stream_get_contents($keyHandle, 257);
fclose($keyHandle);
if (false === $secretEncoded || 256 < strlen($secretEncoded)) {
    fwrite(STDERR, "Signing key is malformed.\n");
    exit(2);
}
$secretEncoded = trim($secretEncoded);
try {
    $secret = sodium_base642bin($secretEncoded, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
} catch (SodiumException) {
    fwrite(STDERR, "Signing key is malformed.\n");
    exit(2);
} finally {
    sodium_memzero($secretEncoded);
}
if (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($secret)) {
    sodium_memzero($secret);
    fwrite(STDERR, "Signing key is malformed.\n");
    exit(2);
}
$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($artifact, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact contains an unsafe object.\n");
        exit(2);
    }
    $relative = substr($file->getPathname(), strlen(rtrim($artifact, '/')) + 1);
    if ('' === $relative || str_contains($relative, "\0") || 1 === preg_match('~(^|/)\.\.(/|$)~', $relative)) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact path is unsafe.\n");
        exit(2);
    }
    $digest = hash_file('sha256', $file->getPathname());
    if (false === $digest) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact file cannot be hashed.\n");
        exit(2);
    }
    $files[$relative] = ['path' => $file->getPathname(), 'sha256' => $digest];
}
ksort($files, SORT_STRING);
foreach (['bin/console', 'composer.lock', 'config/services.yaml', 'public/index.php'] as $requiredRuntimeFile) {
    if (!isset($files[$requiredRuntimeFile])) {
        sodium_memzero($secret);
        fwrite(STDERR, "Artifact is missing a required runtime file.\n");
        exit(2);
    }
}
$servicesSize = filesize($files['config/services.yaml']['path']);
$servicesBytes = false === $servicesSize || 1048576 < $servicesSize ? false : file_get_contents($files['config/services.yaml']['path']);
$versionMatches = [];
if (false === $servicesBytes || 1 !== preg_match_all('/^\s*emailer\.app_version:\s*[\'\"]([^\'\"]+)[\'\"]\s*$/m', $servicesBytes, $versionMatches) || 1 !== count($versionMatches[1]) || $options['version'] !== $versionMatches[1][0]) {
    sodium_memzero($secret);
    fwrite(STDERR, "Artifact application version differs from the release version.\n");
    exit(2);
}
$filename = 'emailer-light-'.$options['version'].'.zip';
$archive = $outputDirectory.'/'.$filename;
foreach ([$archive, $archive.'.manifest.json', $archive.'.sha256', $archive.'.spdx.json', $archive.'.sig'] as $target) {
    if (file_exists($target) || is_link($target)) {
        sodium_memzero($secret);
        fwrite(STDERR, "Release output already exists.\n");
        exit(2);
    }
}
$zip = new ZipArchive();
if (true !== $zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL)) {
    sodium_memzero($secret);
    fwrite(STDERR, "Release archive cannot be created.\n");
    exit(2);
}
$epoch = max(315532800, (int) $options['source-date-epoch']);
foreach ($files as $relative => $entry) {
    if (!$zip->addFile($entry['path'], $relative) || !$zip->setMtimeName($relative, $epoch) || !$zip->setCompressionName($relative, ZipArchive::CM_DEFLATE, 9)) {
        $zip->close();
        @unlink($archive);
        sodium_memzero($secret);
        fwrite(STDERR, "Release archive assembly failed.\n");
        exit(2);
    }
}
$zip->close();
$digests = [];
foreach ($files as $relative => $entry) {
    $digests[$relative] = $entry['sha256'];
}
$manifest = ['format' => 'emailer-light-release-v1', 'version' => $options['version'], 'source_commit' => $options['source'], 'minimum_source_version' => $options['minimum-source'], 'supported_source_versions' => array_values($supportedSources), 'minimum_schema_version' => $minimumSchema, 'schema_version' => $options['schema'], 'rollback_schema_compatible' => 'true' === $options['rollback-schema-compatible'], 'platform' => ['php_min' => '8.4.0', 'php_max_exclusive' => '8.6.0', 'mariadb' => ['10.11', '11.8']], 'files' => $digests];
$manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$archiveHash = hash_file('sha256', $archive);
if (false === $archiveHash) {
    sodium_memzero($secret);
    fwrite(STDERR, "Release archive cannot be hashed.\n");
    exit(2);
}
$checksumBytes = $archiveHash.'  '.$filename."\n";
$lock = json_decode((string) file_get_contents($artifact.'/composer.lock'), true, 64, JSON_THROW_ON_ERROR);
$packages = [];
foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
    if (is_array($package) && is_string($package['name'] ?? null) && is_string($package['version'] ?? null)) {
        $licenses = is_array($package['license'] ?? null) ? array_values(array_filter($package['license'], static fn (mixed $license): bool => is_string($license) && '' !== trim($license))) : [];
        $declaredLicense = 1 === count($licenses) ? $licenses[0] : (1 < count($licenses) ? '('.implode(' OR ', $licenses).')' : 'NOASSERTION');
        $packages[] = ['SPDXID' => 'SPDXRef-Package-'.preg_replace('/[^A-Za-z0-9.-]/', '-', $package['name']).'-'.substr(hash('sha256', $package['name']), 0, 12), 'name' => $package['name'], 'versionInfo' => $package['version'], 'downloadLocation' => 'NOASSERTION', 'filesAnalyzed' => false, 'licenseConcluded' => 'NOASSERTION', 'licenseDeclared' => $declaredLicense, 'copyrightText' => 'NOASSERTION'];
    }
}
usort($packages, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);
$sbom = ['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'Emailer Light '.$options['version'], 'documentNamespace' => 'https://attory.example.test/spdx/emailer-light/'.$options['source'].'/'.$options['version'], 'creationInfo' => ['created' => gmdate('Y-m-d\TH:i:s\Z', $epoch), 'creators' => ['Tool: emailer-light-phase9-builder']], 'packages' => $packages];
$sbomBytes = json_encode($sbom, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
$signature = sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $secret);
sodium_memzero($secret);
try {
    writeExclusive($archive.'.manifest.json', $manifestBytes);
    writeExclusive($archive.'.sha256', $checksumBytes);
    writeExclusive($archive.'.spdx.json', $sbomBytes);
    writeExclusive($archive.'.sig', sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
    foreach ([$archive, $archive.'.manifest.json', $archive.'.sha256', $archive.'.spdx.json', $archive.'.sig'] as $target) {
        if (!chmod($target, 0o644)) {
            throw new RuntimeException('Release output permissions cannot be finalized.');
        }
    }
} catch (Throwable) {
    foreach ([$archive, $archive.'.manifest.json', $archive.'.sha256', $archive.'.spdx.json', $archive.'.sig'] as $target) {
        @unlink($target);
    }
    fwrite(STDERR, "Release metadata publication failed.\n");
    exit(2);
}
fwrite(STDOUT, $archive."\n");

function writeExclusive(string $path, string $contents): void
{
    $handle = @fopen($path, 'xb');
    if (false === $handle || !chmod($path, 0o600)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new RuntimeException('Release sidecar cannot be created.');
    }
    try {
        $offset = 0;
        while ($offset < strlen($contents)) {
            $written = fwrite($handle, substr($contents, $offset));
            if (false === $written || 0 === $written) {
                throw new RuntimeException('Release sidecar cannot be written.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('Release sidecar cannot be flushed.');
        }
    } finally {
        fclose($handle);
    }
}
