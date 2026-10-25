<?php

declare(strict_types=1);

use App\Module\Release\Application\NativeSchemaProof;
use Doctrine\DBAL\Connection;

require dirname(__DIR__, 2).'/vendor/autoload.php';
if (!in_array($argv[1] ?? '', ['plan', 'verify'], true)) {
    throw new RuntimeException('Use the bounded migration plan or target verification mode.');
}
$root = dirname(__DIR__, 2);
$files = glob($root.'/migrations/*');
sort($files);
if ([$root.'/migrations/Version20261014000000.php', $root.'/migrations/Version20261025000000.php'] !== $files || 'ad89a404542760fba377bb421e92c52162bd52e0eca13b5a5a947b64e700c97d' !== hash_file('sha256', $files[0])) {
    throw new RuntimeException('The unchanged baseline and single allowed DDL migration inventory failed.');
}
$proof = json_decode((string) file_get_contents($root.'/config/upgrade/native-schema-1.2.6.json'), true, 512, JSON_THROW_ON_ERROR);
if (($proof['migration_sha256'] ?? null) !== hash_file('sha256', $files[1])) {
    throw new RuntimeException('The exact reviewed DDL migration hash failed.');
}
$kernel = new App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$database = $container->get(Connection::class);
if (!$database instanceof Connection) {
    throw new RuntimeException('The test connection is unavailable.');
}
$ledger = $database->createSchemaManager()->tablesExist(['doctrine_migration_versions']) ? $database->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version') : [];
$source = ['DoctrineMigrations\\Version20261014000000'];
$target = [...$source, 'DoctrineMigrations\\Version20261025000000'];
if ('plan' === $argv[1]) {
    echo match ($ledger) {
        [] => 'baseline', $source => 'source', $target => 'target', default => throw new RuntimeException('Unexpected migration ledger; refusing any additional or reverse migration.'),
    };
} elseif ($ledger !== $target || ($proof['definition'] ?? null) !== NativeSchemaProof::capture($database)) {
    throw new RuntimeException('The exact complete target DDL and two-entry migration ledger do not match.');
} else {
    echo "Complete target DDL, exact migration ledger and unchanged baseline verified.\n";
}
$kernel->shutdown();
