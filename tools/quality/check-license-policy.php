<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 32, JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($root.'/composer.lock'), true, 64, JSON_THROW_ON_ERROR);

if ('AGPL-3.0-or-later' !== ($composer['license'] ?? null)) {
    fwrite(STDERR, "The Emailer Light project license declaration is missing or unexpected.\n");
    exit(1);
}

$allowed = [
    'Apache-2.0' => true,
    'BSD-2-Clause' => true,
    'BSD-3-Clause' => true,
    'ISC' => true,
    'MIT' => true,
    'MPL-2.0' => true,
];
$packages = array_merge(is_array($lock['packages'] ?? null) ? $lock['packages'] : [], is_array($lock['packages-dev'] ?? null) ? $lock['packages-dev'] : []);
if (!is_array($packages) || [] === $packages) {
    fwrite(STDERR, "The dependency license inventory is unavailable.\n");
    exit(1);
}

$checked = 0;
foreach ($packages as $package) {
    $licenses = is_array($package) ? ($package['license'] ?? null) : null;
    if (!is_array($licenses) || [] === $licenses) {
        fwrite(STDERR, "A dependency has no declared license.\n");
        exit(1);
    }
    foreach ($licenses as $license) {
        if (!is_string($license) || !isset($allowed[$license])) {
            fwrite(STDERR, "A dependency has a license outside the reviewed allowlist.\n");
            exit(1);
        }
    }
    ++$checked;
}

fwrite(STDOUT, sprintf("Runtime and development dependency license policy verified for %d packages.\n", $checked));
