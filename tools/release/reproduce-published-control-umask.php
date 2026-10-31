<?php

declare(strict_types=1);

// Acceptance-only: authenticate immutable affected package, demonstrate its
// nonroot failure without network, database, real recipients or source patches.
ini_set('zend.exception_ignore_args', '1');
if (0 !== posix_geteuid() || 3 !== $argc) {
    exit(2);
}
require dirname(__DIR__, 2).'/src/Module/Release/Application/PackageInspector.php';
$archive = $argv[1];
$pin = $argv[2];
$version = '1.2.11';
$manifest = (new App\Module\Release\Application\PackageInspector($pin, $version))->inspectInstalledSource($archive);
if ('3217359f19963b915b31a8be6f40485620dcb12b' !== $manifest['source_commit'] || $manifest['version'] !== $version) {
    throw new RuntimeException('Expected immutable affected package is required.');
}
$zip = new ZipArchive();
if (true !== $zip->open($archive)) {
    throw new RuntimeException('Authenticated package could not be reopened.');
}
$base = '/run/emailer-published-control-'.bin2hex(random_bytes(8));
mkdir($base, 0o700);
chmod($base, 0o755);
foreach (['DeliveryControlFiles.php', 'DeliveryIntentLease.php'] as $file) {
    $path = $base.'/'.$file;
    $bytes = $zip->getFromName('src/Shared/'.$file);
    if (!is_string($bytes) || hash('sha256', $bytes) !== ($manifest['files']['src/Shared/'.$file] ?? null)) {
        throw new RuntimeException('Published regression runtime is not authenticated.');
    }
    file_put_contents($path, $bytes);
    chmod($path, 0o444);
    require $path;
}
$zip->close();
$uuid = '01900000-0000-7000-8000-000000000001';
$controls = new App\Shared\DeliveryControlFiles($base.'/private', $uuid);
$mask = umask(0o077);
try {
    $controls->prepare(1000, 1000, 'enabled');
    $controls->hold('01900000-0000-7000-8000-000000000002', 'host', ['source' => 'synthetic']);
} finally {
    umask($mask);
}
$held = 1 === count($controls->status()['holds']);
if (!posix_setgid(1000) || !posix_setuid(1000) || 1000 !== posix_geteuid()) {
    throw new RuntimeException('Nonroot regression actor is unavailable.');
}
$state = $controls->status();
$claim = $controls->claimLease();
$claim->release();
$reproduced = $held && !$state['configured'] && 'legacy' === $state['owner'] && [] === $state['holds'];
echo json_encode(['published_version' => $version, 'source_commit' => $manifest['source_commit'], 'published_archive_sha256' => $manifest['_verified_archive_sha256'], 'actor_uid' => posix_geteuid(), 'root_had_active_hold' => $held, 'misclassified_legacy' => !$state['configured'], 'unfenced_claim_reproduced' => $reproduced, 'network_or_mail_used' => false], JSON_THROW_ON_ERROR)."\n";
exit($reproduced ? 0 : 1);
