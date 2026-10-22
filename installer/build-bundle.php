<?php

declare(strict_types=1);

// Release tooling only. The existing edition signing identity is supplied externally.
$options = getopt('', ['candidate:', 'source:', 'version:', 'public-key:', 'signing-key:', 'output:', 'test-only', 'predecessors:']);
foreach (['candidate', 'source', 'version', 'public-key', 'signing-key', 'output'] as $name) {
    if (!is_string($options[$name] ?? null)) {
        throw new RuntimeException('Missing bundle input.');
    }
}
$root = dirname(__DIR__);
$product = 'emailer-light';
$prefix = 'emailer-light';
$archive = $prefix.'-'.$options['version'].'.zip';
require_once $root.'/src/Module/Release/Application/PackageInspector.php';
$inspection = (new App\Module\Release\Application\PackageInspector($options['public-key'], $options['version']))->inspect($options['candidate'].'/'.$archive);
if ($inspection['version'] !== $options['version'] || $inspection['source_commit'] !== $options['source']) {
    throw new RuntimeException('Bundle source identity differs from the authenticated runtime.');
}
$keyStat = lstat($options['signing-key']);
if (!is_array($keyStat) || 0o100000 !== ($keyStat['mode'] & 0o170000) || 0 !== ($keyStat['mode'] & 0o077) || 1 !== $keyStat['nlink'] || 256 < $keyStat['size'] || str_starts_with(realpath($options['signing-key']) ?: '', $root.'/')) {
    throw new RuntimeException('Signing key custody is unsafe.');
}
$key = sodium_base642bin(trim((string) file_get_contents($options['signing-key'])), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
$public = trim((string) file_get_contents($options['public-key']));
if (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($key) || !hash_equals(sodium_crypto_sign_publickey_from_secretkey($key), sodium_base642bin($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING))) {
    throw new RuntimeException('Existing edition signing identity does not match.');
}
try {
    $testOnly = array_key_exists('test-only', $options);
    if (!$testOnly && $public !== trim((string) file_get_contents($root.'/release/emailer-light-release.pub'))) {
        throw new RuntimeException('Production bundle must use the established edition identity.');
    }
    $contents = [];
    foreach (['', '.manifest.json', '.sha256', '.spdx.json', '.sig'] as $suffix) {
        $contents[$archive.$suffix] = (string) file_get_contents($options['candidate'].'/'.$archive.$suffix);
    }
    if (!is_string($options['predecessors'] ?? null)) {
        throw new RuntimeException('The self-contained installer requires its authenticated predecessor package set.');
    }
    $priorArchives = ['emailer-light-1.0.0.zip', 'emailer-light-1.1.0.zip', 'emailer-light-1.2.0.zip', 'emailer-light-1.2.1.zip', 'emailer-light-1.2.2.zip', 'emailer-light-1.2.3.zip'];
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.0.0'))->inspectInstalledSource($options['predecessors'].'/'.$priorArchives[0]);
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.1.0'))->inspectInstalledSource($options['predecessors'].'/emailer-light-1.1.0.zip');
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.2.0'))->inspectInstalledSource($options['predecessors'].'/emailer-light-1.2.0.zip');
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.2.1'))->inspectInstalledSource($options['predecessors'].'/emailer-light-1.2.1.zip');
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.2.2'))->inspectInstalledSource($options['predecessors'].'/emailer-light-1.2.2.zip');
    (new App\Module\Release\Application\PackageInspector($options['public-key'], '1.2.3'))->inspectInstalledSource($options['predecessors'].'/emailer-light-1.2.3.zip');
    foreach ($priorArchives as $prior) {
        foreach (['', '.manifest.json', '.sha256', '.spdx.json', '.sig'] as $suffix) {
            $path = $options['predecessors'].'/'.$prior.$suffix;
            $info = lstat($path);
            if (!is_array($info) || 0o100000 !== ($info['mode'] & 0o170000) || 1 !== $info['nlink']) {
                throw new RuntimeException('Predecessor evidence is missing or unsafe.');
            }
            $bytes = file_get_contents($path);
            if (false === $bytes) {
                throw new RuntimeException('Predecessor evidence cannot be read.');
            }
            $contents[$prior.$suffix] = $bytes;
        }
    }
    foreach (['provision.py', 'resources.py', 'command.py', 'manage.py', 'runtime_env.py', 'hosting.py', 'packages.py', 'isolated_database.py', 'ubuntu-php.asc', 'apt-guard.py', 'host.py', 'host-database.php'] as $file) {
        $contents[$file] = (string) file_get_contents(__DIR__.'/'.$file);
    }
    $contents['verify-package.php'] = (string) file_get_contents($root.'/tools/release/verify-package.php');
    $contents['PackageInspector.php'] = (string) file_get_contents($root.'/src/Module/Release/Application/PackageInspector.php');
    $contents['release.pub'] = $public."\n";
    ksort($contents, SORT_STRING);
    $digests = array_map(static fn (string $bytes): string => hash('sha256', $bytes), $contents);
    $manifest = json_encode(['format' => 'emailer-installer-v1', 'product' => $product, 'test_only' => $testOnly, 'schema_version' => '20261014000000', 'version' => $options['version'], 'source_commit' => $options['source'], 'archive' => $archive, 'files' => $digests], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    $contents['installer.json'] = $manifest;
    $contents['installer.json.sig'] = sodium_crypto_sign_detached($manifest, $key);
    $contents['setup'] = str_replace(['@PUBLIC_KEY@', '@PRODUCT@', '@BOOTSTRAP@'], [$public, $product, (string) file_get_contents(__DIR__.'/bootstrap.sh.in')], (string) file_get_contents(__DIR__.'/setup.in'));
    $zip = new ZipArchive();
    if (true !== $zip->open($options['output'], ZipArchive::CREATE | ZipArchive::EXCL)) {
        throw new RuntimeException('Bundle output must not already exist.');
    }
    $epoch = 315532800;
    foreach ($contents as $name => $bytes) {
        $zip->addFromString($name, $bytes);
        $zip->setMtimeName($name, $epoch);
        $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, ('setup' === $name ? 0o100755 : 0o100644) << 16);
    }
    $zip->close();
    $launcher = $contents['setup']."\n";
    unset($contents);
    $payload = fopen($options['output'], 'rb');
    $temporary = $options['output'].'.assembling';
    $assembled = fopen($temporary, 'xb');
    if (false === $payload || false === $assembled) {
        throw new RuntimeException('Self-contained setup output could not be opened.');
    }
    try {
        $size = fstat($payload);
        if (false === $size || strlen($launcher) !== fwrite($assembled, $launcher) || $size['size'] !== stream_copy_to_stream($payload, $assembled) || !fflush($assembled) || !fsync($assembled)) {
            throw new RuntimeException('Self-contained setup file could not be assembled completely.');
        }
    } finally {
        fclose($payload);
        fclose($assembled);
    }
    if (!rename($temporary, $options['output']) || !chmod($options['output'], 0o755)) {
        throw new RuntimeException('Self-contained setup file could not be published.');
    }
} finally {
    sodium_memzero($key);
}
