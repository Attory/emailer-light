<?php

declare(strict_types=1);

use App\Module\Secrets\Infrastructure\FilesystemMasterKey;
use App\Module\Secrets\Infrastructure\PosixDirectoryDurability;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (5 !== $argc || !in_array($argv[1], ['create', 'load', 'replace'], true)) {
    fwrite(STDERR, "Usage: check-master-key-access.php create|load|replace KEY PUBLIC_DIR PROJECT_DIR\n");
    exit(2);
}

$provider = new FilesystemMasterKey($argv[2], $argv[3], $argv[4], 'prod', new PosixDirectoryDurability());
try {
    if ('create' === $argv[1]) {
        $provider->create();
    }
    $key = $provider->load();
    if ('replace' === $argv[1]) {
        $replacement = random_bytes(32);
        try {
            $provider->replace($key, $replacement);
        } finally {
            sodium_memzero($replacement);
        }
    }
    sodium_memzero($key);
} catch (Throwable) {
    fwrite(STDERR, "Master-key privilege-boundary check failed.\n");
    exit(1);
}
