<?php

declare(strict_types=1);

use App\Module\Release\Application\PackageInspector;

$autoload = dirname(__DIR__, 2).'/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} elseif (is_file(__DIR__.'/PackageInspector.php')) {
    require __DIR__.'/PackageInspector.php';
} else {
    fwrite(STDERR, "Release verifier runtime is incomplete.\n");
    exit(2);
}

$options = getopt('', ['archive:', 'public-key:', 'current-version:', 'expected-version:', 'expected-source:', 'installed-source']);
foreach (['archive', 'public-key', 'current-version', 'expected-version', 'expected-source'] as $required) {
    if (!is_string($options[$required] ?? null) || '' === $options[$required]) {
        fwrite(STDERR, "Missing required verification option.\n");
        exit(2);
    }
}

try {
    $inspector = new PackageInspector($options['public-key'], $options['current-version']);
    $manifest = array_key_exists('installed-source', $options)
        ? $inspector->inspectInstalledSource($options['archive'])
        : $inspector->inspect($options['archive']);
    if ($options['expected-version'] !== ($manifest['version'] ?? null) || $options['expected-source'] !== ($manifest['source_commit'] ?? null)) {
        throw new RuntimeException('Verified package identity differs from the expected release.');
    }
} catch (Throwable) {
    fwrite(STDERR, "Release package verification failed.\n");
    exit(2);
}

fwrite(STDOUT, "Release package verified.\n");
