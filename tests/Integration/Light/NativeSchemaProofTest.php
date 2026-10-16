<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Release\Application\NativeSchemaProof;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class NativeSchemaProofTest extends KernelTestCase
{
    public function testLedgerDefaultsDoNotRelaxApplicationOrStructuralProofs(): void
    {
        $ledger = 'CREATE TABLE `doctrine_migration_versions` (`version` varchar(191) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        $canonical = NativeSchemaProof::baselineTable('doctrine_migration_versions', $ledger);
        foreach (['bin', 'unicode_ci', 'general_ci', 'uca1400_ai_ci'] as $collation) {
            self::assertSame($canonical, NativeSchemaProof::baselineTable('doctrine_migration_versions', str_replace('utf8mb4_bin', 'utf8mb4_'.$collation, $ledger)));
        }
        foreach (['varchar(191)' => 'varchar(190)', 'utf8mb4_bin' => 'utf8mb4_unicode_520_ci', 'NOT NULL' => 'DEFAULT NULL'] as $before => $after) {
            self::assertNotSame($canonical, NativeSchemaProof::baselineTable('doctrine_migration_versions', str_replace($before, $after, $ledger)));
        }
        $application = str_replace('doctrine_migration_versions', 'app_user', $ledger);
        self::assertNotSame($application, NativeSchemaProof::baselineTable('app_user', str_replace('utf8mb4_bin', 'utf8mb4_uca1400_ai_ci', $application)));
    }

    public function testLiveBaselineMatchesTheSignedUpdateProof(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $bytes = file_get_contents(\dirname(__DIR__, 3).'/config/upgrade/native-schema-1.0.0.json');
        self::assertIsString($bytes);
        $expected = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        self::assertSame(hash_file('sha256', \dirname(__DIR__, 3).'/migrations/Version20261014000000.php'), $expected['migration_sha256']);
        self::assertSame($expected['definition'], NativeSchemaProof::capture($connection));
    }

    public function testChangedImmutabilityTriggerBodyCannotPassAsTheSameBaseline(): void
    {
        self::bootKernel();
        $connection = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connection);
        $before = NativeSchemaProof::capture($connection);
        $definition = $connection->fetchAssociative('SHOW CREATE TRIGGER audit_event_no_delete');
        self::assertIsArray($definition);
        self::assertIsString($definition['SQL Original Statement']);
        $connection->executeStatement('DROP TRIGGER audit_event_no_delete');
        try {
            $connection->executeStatement('CREATE TRIGGER audit_event_no_delete BEFORE DELETE ON audit_event FOR EACH ROW SET @native_update_test_only = 1');
            self::assertNotSame($before, NativeSchemaProof::capture($connection));
        } finally {
            $connection->executeStatement('DROP TRIGGER IF EXISTS audit_event_no_delete');
            $connection->executeStatement($definition['SQL Original Statement']);
        }
        self::assertSame($before, NativeSchemaProof::capture($connection));
    }
}
