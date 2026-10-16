<?php

declare(strict_types=1);

// Maintainer-only documentation correction; never builds or replaces a runtime.
if (5 !== $argc || file_exists($argv[3])) {
    throw new RuntimeException('Expected original Light assets, rendered guides, new output and original signing key.');
}
$arguments = $argv;
$root = dirname(__DIR__, 2);
$git = static function (string $operation) use ($root): string {
    $value = shell_exec('git -C '.escapeshellarg($root).' '.$operation);
    if (!is_string($value)) {
        throw new RuntimeException('Documentation source identity could not be read.');
    }

    return trim($value);
};
if ('' !== $git('status --porcelain --untracked-files=normal')) {
    throw new RuntimeException('Build documentation only from a clean reviewed commit.');
}
$source = $git('rev-parse HEAD');
$epoch = $git('show -s --format=%ct HEAD');
if (1 !== preg_match('/^[a-f0-9]{40}$/D', $source) || !ctype_digit($epoch)) {
    throw new RuntimeException('Documentation source identity is invalid.');
}
(static function (string $directory, string $commit): void {
    $argv = [__FILE__, $directory, $commit];
    $argc = 3;
    require __DIR__.'/verify-built-guides.php';
})($arguments[2], $source);
$read = static function (string $path): string {
    $stat = lstat($path);
    if (!is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || $stat['size'] > 16777216) {
        throw new RuntimeException('An authenticated release input is not a regular bounded file.');
    }
    $value = file_get_contents($path);
    if (false === $value) {
        throw new RuntimeException('A release input could not be read.');
    }

    return $value;
};
$pin = sodium_base642bin('2zO47r_uQtv-lcMryvh_wWijpyCF1YAfBXBGi9W8B1E', SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$sums = $read($arguments[1].'/SHA256SUMS');
$signature = sodium_base642bin(trim($read($arguments[1].'/SHA256SUMS.sig')), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
if (!sodium_crypto_sign_verify_detached($signature, $sums, $pin)) {
    throw new RuntimeException('The original Light release inventory is not authentic.');
}
$inventory = [];
foreach (explode("\n", trim($sums)) as $line) {
    if (1 !== preg_match('/^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$/D', $line, $match) || isset($inventory[$match[2]])) {
        throw new RuntimeException('The original inventory is malformed.');
    }
    $inventory[$match[2]] = $match[1];
}
$authenticated = static function (string $name) use ($arguments, $inventory, $read): string {
    $bytes = $read($arguments[1].'/'.$name);
    if (!isset($inventory[$name]) || !hash_equals($inventory[$name], hash('sha256', $bytes))) {
        throw new RuntimeException('An original release identity failed authentication.');
    }

    return $bytes;
};
$identity = json_decode($authenticated('BUILD-IDENTITY.json'), true, 32, JSON_THROW_ON_ERROR);
$manifest = json_decode($authenticated('emailer-light-1.1.0.zip.manifest.json'), true, 64, JSON_THROW_ON_ERROR);
$runtime = $arguments[1].'/emailer-light-1.1.0.zip';
$stat = lstat($runtime);
if (!is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000)) {
    throw new RuntimeException('The original runtime must be a regular file.');
}
$digest = hash_file('sha256', $runtime);
if (!is_string($digest) || !isset($inventory['emailer-light-1.1.0.zip']) || !hash_equals($inventory['emailer-light-1.1.0.zip'], $digest)
    || 'emailer-light' !== ($identity['product'] ?? null) || '1.1.0' !== ($identity['version'] ?? null) || false !== ($identity['test_only'] ?? null)
    || 'emailer-light-release-v1' !== ($manifest['format'] ?? null) || '1.1.0' !== ($manifest['version'] ?? null)
    || !is_string($identity['source_commit'] ?? null) || 1 !== preg_match('/^[a-f0-9]{40}$/D', $identity['source_commit'])
    || $identity['source_commit'] !== ($manifest['source_commit'] ?? null)) {
    throw new RuntimeException('The original runtime release binding is invalid.');
}
$docs = json_decode($read($arguments[2].'/DOCS-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
$files = ['DOCS-MANIFEST.json' => $read($arguments[2].'/DOCS-MANIFEST.json')];
foreach (array_keys($docs['outputs']) as $name) {
    $files[$name] = $read($arguments[2].'/'.$name);
}
$files['DOCUMENTATION-RELEASE.json'] = json_encode([
    'format' => 'emailer-light-docs-release-v1', 'product' => 'emailer-light',
    'application_version' => '1.1.0', 'documentation_revision' => 1, 'release_tag' => 'docs-v1.1.0-r1',
    'source_commit' => $source, 'runtime_source_commit' => $identity['source_commit'], 'runtime_sha256' => $digest,
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
if (!mkdir($arguments[3], 0o755)) {
    throw new RuntimeException('The new documentation release directory could not be created.');
}
ksort($files);
$zip = new ZipArchive();
$archive = 'emailer-light-1.1.0-documentation-r1.zip';
if (true !== $zip->open($arguments[3].'/'.$archive, ZipArchive::CREATE | ZipArchive::EXCL)) {
    throw new RuntimeException('The documentation archive could not be created.');
}
foreach ($files as $name => $bytes) {
    if (false === file_put_contents($arguments[3].'/'.$name, $bytes) || !$zip->addFromString($name, $bytes) || !$zip->setMtimeName($name, (int) $epoch)) {
        throw new RuntimeException('The documentation archive could not be populated.');
    }
}
if (!$zip->close()) {
    throw new RuntimeException('The documentation archive could not be finalized.');
}
$files[$archive] = $read($arguments[3].'/'.$archive);
ksort($files);
$sums = '';
foreach ($files as $name => $bytes) {
    $sums .= hash('sha256', $bytes).'  '.$name."\n";
}
file_put_contents($arguments[3].'/SHA256SUMS', $sums);
(static function (string $key, string $directory): void {
    $argv = [__FILE__, $key, $directory.'/SHA256SUMS', $directory.'/SHA256SUMS.sig'];
    $argc = 4;
    require dirname(__DIR__).'/release/sign-checksums.php';
})($arguments[4], $arguments[3]);
$signature = sodium_base642bin(trim($read($arguments[3].'/SHA256SUMS.sig')), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
if (!sodium_crypto_sign_verify_detached($signature, $sums, $pin)) {
    throw new RuntimeException('Use the original Light signing identity for documentation corrections.');
}
echo "Corrected documentation authenticated, runtime-bound and signed; no runtime or update asset created.\n";
