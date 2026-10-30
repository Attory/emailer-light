<?php

declare(strict_types=1);

// Privileged installer RPC: protected stdin only, no diagnostic credential output.
if (0 !== posix_geteuid()) {
    exit(1);
}
try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException();
    }
    require $input['bootstrap'].'/vendor/autoload.php';
    $connection = \Doctrine\DBAL\DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($input['database_url']));
    $control = new \App\Module\Release\Application\InstallerDeliveryControl($connection, $input['private_directory']);
    if ('begin-fresh' === $input['action']) {
        $control->beginFresh($input['operation'], $input['proof'], $input['uid'], $input['gid']);
    } elseif ('complete-fresh' === $input['action']) {
        $control->completeFresh($input['operation'], $input['proof']);
    } else {
        throw new RuntimeException();
    }
    echo "Operation-owned delivery control verified.\n";
} catch (Throwable) {
    fwrite(STDERR, "Installer delivery control validation failed. Retain its protected operation evidence and use the supported recovery action.\n");
    exit(1);
}
