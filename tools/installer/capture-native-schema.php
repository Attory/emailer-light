<?php

declare(strict_types=1);

// Test/release tooling. DATABASE_URL must identify a freshly migrated disposable baseline.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$dsn = getenv('DATABASE_URL');
if (!is_string($dsn) || '' === $dsn || 2 !== $argc) {
    throw new RuntimeException('Use a disposable baseline database and one output path.');
}
$connection = Doctrine\DBAL\DriverManager::getConnection((new Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($dsn));
$proof = ['format' => 'emailer-light-native-schema-v1', 'migration_sha256' => hash_file('sha256', dirname(__DIR__, 2).'/migrations/Version20261014000000.php'), 'definition' => App\Module\Release\Application\NativeSchemaProof::capture($connection)];
if (false === file_put_contents($argv[1], json_encode($proof, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) {
    throw new RuntimeException('Schema evidence could not be written.');
}
