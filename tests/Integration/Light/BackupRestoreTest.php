<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Analytics\Infrastructure\AnalyticsBackupContributor;
use App\Module\Audit\Infrastructure\AuditBackupContributor;
use App\Module\Backup\Application\BackupEngine;
use App\Module\Backup\Infrastructure\BackupRecordsContributor;
use App\Module\Delivery\Infrastructure\DeliveryBackupContributor;
use App\Module\Health\Infrastructure\HealthBackupContributor;
use App\Module\Housekeeping\Infrastructure\HousekeepingBackupContributor;
use App\Module\Identity\Infrastructure\IdentityBackupContributor;
use App\Module\Operations\Infrastructure\OperationsBackupContributor;
use App\Module\Release\Infrastructure\ReleaseBackupContributor;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Secrets\Infrastructure\SecretsBackupContributor;
use App\Module\Setup\Infrastructure\SetupBackupContributor;
use App\Module\Suppression\Infrastructure\SuppressionBackupContributor;
use App\Module\Templates\Infrastructure\TemplatesBackupContributor;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class BackupRestoreTest extends KernelTestCase
{
    public function testEncryptedBackupVerificationAndQuiescedRestorePreserveSuppressionAndEditionIdentity(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $root = sys_get_temp_dir().'/light-backup-'.bin2hex(random_bytes(8));
        mkdir($root, 0o700);
        mkdir($root.'/assets', 0o700);
        $key = new class implements MasterKey {
            public function create(): void
            {
            }

            public function load(): string
            {
                return str_repeat('k', 32);
            }

            public function exists(): bool
            {
                return true;
            }

            public function recover(string $key): void
            {
            }

            public function replace(string $expectedCurrentKey, string $replacementKey): void
            {
            }
        };
        $locks = new LockFactory(new InMemoryStore());
        $contributors = [new SetupBackupContributor(), new IdentityBackupContributor(), new TemplatesBackupContributor($root.'/assets'), new DeliveryBackupContributor(), new SuppressionBackupContributor(), new SecretsBackupContributor(), new AnalyticsBackupContributor(), new AuditBackupContributor(), new HealthBackupContributor(), new HousekeepingBackupContributor(), new OperationsBackupContributor(), new ReleaseBackupContributor(), new BackupRecordsContributor()];
        $backedUpTables = [];
        foreach ($contributors as $contributor) {
            array_push($backedUpTables, ...$contributor->tables());
        }
        $backedUpTables = array_values(array_unique($backedUpTables));
        $schemaTables = $database->createSchemaManager()->listTableNames();
        sort($backedUpTables);
        sort($schemaTables);
        self::assertSame($schemaTables, $backedUpTables);
        $engine = new BackupEngine($database, $key, $locks, new MaintenanceOperationLock($locks), $contributors, $root, '1.0.0');
        $suppressionId = '01990101-0000-7000-8000-000000000081';
        try {
            $database->insert('suppression', ['id' => $suppressionId, 'email_canonical' => 'backup@example.test', 'reason' => 'manual', 'source' => 'synthetic', 'created_at' => '2026-01-01 00:00:00.000000']);
            $backup = $engine->create();
            self::assertSame('emailer-light-backup-v1', $engine->verify($backup['path'])['format']);
            $database->delete('suppression', ['id' => $suppressionId]);
            $database->update('delivery_runtime', ['accepting_work' => 0, 'active_claims' => 0], ['singleton_key' => '1']);
            $engine->restore($backup['path']);
            self::assertSame('backup@example.test', $database->fetchOne('SELECT email_canonical FROM suppression WHERE id = ?', [$suppressionId]));
            self::assertSame('emailer-light', $database->fetchOne("SELECT product_id FROM product_identity WHERE singleton_key = '1'"));
            $accepting = $database->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'");
            self::assertIsNumeric($accepting);
            self::assertSame(0, (int) $accepting);
        } finally {
            $database->delete('suppression', ['id' => $suppressionId]);
            $database->delete('backup_transfer', []);
            $database->delete('backup_record', []);
            $database->update('delivery_runtime', ['accepting_work' => 1, 'active_claims' => 0], ['singleton_key' => '1']);
            $entries = glob($root.'/*');
            foreach (\is_array($entries) ? $entries : [] as $entry) {
                if (is_dir($entry)) {
                    rmdir($entry);
                } else {
                    unlink($entry);
                }
            }
            rmdir($root);
            self::ensureKernelShutdown();
        }
    }
}
