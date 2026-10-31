<?php

declare(strict_types=1);

// Signed installer read-only probe; execute as the actual service account.
ini_set('zend.exception_ignore_args', '1');
try {
    if (0 >= posix_geteuid() || !in_array($argc, [3, 4], true)) {
        throw new RuntimeException();
    }
    $root = $argv[1];
    $private = $argv[2];
    if (realpath($root) !== $root || is_link($root)) {
        throw new RuntimeException();
    }
    require $root.'/vendor/autoload.php';
    $identity = $argv[3] ?? null;
    if (null === $identity) {
        $lock = $private.'/install.lock';
        $stat = lstat($lock);
        if (false === $stat || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 4096 < $stat['size']) {
            throw new RuntimeException();
        }
        $value = json_decode((string) file_get_contents($lock), true, 16, JSON_THROW_ON_ERROR);
        $identity = $value['installation_uuid'] ?? null;
    }
    if (!is_string($identity)) {
        throw new RuntimeException();
    }
    $files = new App\Shared\DeliveryControlFiles($private, $identity);
    $status = $files->status();
    $namespace = dirname($private).'/delivery-control/'.$identity;
    if (!$status['configured'] || [] === $status['holds'] || !is_writable($namespace.'/owner') || is_writable($namespace.'/holds')) {
        throw new RuntimeException();
    }
    $blocked = false;
    try {
        $lease = $files->claimLease();
        $lease->release();
    } catch (App\Shared\DeliveryControlUnavailable $error) {
        throw $error;
    } catch (DomainException) {
        $blocked = true;
    }
    if (!$blocked) {
        throw new RuntimeException();
    }
    echo json_encode(['delivery_control_access' => true, 'nonroot_uid' => posix_geteuid(), 'held_claim_rejected' => true, 'owner_writable' => true, 'root_holds_read_only' => true], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "delivery_control_unavailable: nonroot service access or hold enforcement failed; keep delivery stopped and retain the installer recovery action.\n");
    exit(1);
}
