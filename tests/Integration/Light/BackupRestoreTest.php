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
        $administratorId = '01990101-0000-7000-8000-000000000091';
        $templateId = '01990101-0000-7000-8000-000000000092';
        $versionId = '01990101-0000-7000-8000-000000000093';
        $campaignId = '01990101-0000-7000-8000-000000000094';
        $recipientId = '01990101-0000-7000-8000-000000000095';
        $deliveryId = '01990101-0000-7000-8000-000000000096';
        try {
            $now = '2026-01-01 00:00:00.000000';
            $database->insert('app_user', ['id' => $administratorId, 'singleton_key' => '1', 'login_identifier' => 'backup-admin@example.test', 'password_hash' => password_hash('synthetic-backup-phrase-2026', PASSWORD_ARGON2ID), 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
            $database->insert('email_template', ['id' => $templateId, 'name' => 'Synthetic backup template', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now]);
            $database->insert('template_version', ['id' => $versionId, 'template_id' => $templateId, 'version_number' => 1, 'subject' => 'Synthetic campaign', 'html_body' => '<p>Synthetic</p>', 'text_body' => 'Synthetic', 'merge_fields_json' => '[]', 'link_manifest_json' => '[]', 'content_sha256' => str_repeat('a', 64), 'created_at' => $now, 'published_at' => $now]);
            $database->update('email_template', ['published_version_id' => $versionId], ['id' => $templateId]);
            $database->insert('campaign', ['id' => $campaignId, 'name' => 'Synthetic approved campaign', 'template_version_id' => $versionId, 'state' => 'approved', 'approved_at' => $now, 'approved_by' => $administratorId, 'created_by' => $administratorId, 'created_at' => $now, 'updated_at' => $now]);
            $database->insert('campaign_audience_recipient', ['campaign_id' => $campaignId, 'recipient_id' => $recipientId, 'email_canonical' => 'backup-recipient@example.test', 'profile_json' => '{}']);
            $database->insert('campaign_audience_snapshot', ['campaign_id' => $campaignId, 'recipient_count' => 1, 'audience_sha256' => hash('sha256', $recipientId."\0backup-recipient@example.test\0{}"), 'created_at' => $now]);
            $database->insert('campaign_recipient', ['id' => $deliveryId, 'campaign_id' => $campaignId, 'recipient_id' => $recipientId, 'state' => 'accepted', 'attempt_count' => 1, 'accepted_at' => $now, 'terminal_at' => $now, 'message_id' => 'synthetic-backup-message', 'operation_version' => 1, 'updated_at' => $now]);
            $database->insert('suppression', ['id' => $suppressionId, 'email_canonical' => 'backup@example.test', 'reason' => 'manual', 'source' => 'synthetic', 'created_at' => '2026-01-01 00:00:00.000000']);
            $oldTimestamp = $database->fetchOne('SELECT executed_at FROM doctrine_migration_versions WHERE version = ?', ['DoctrineMigrations\\Version20261014000000']);
            $database->update('doctrine_migration_versions', ['executed_at' => '2099-01-01 00:00:00'], ['version' => 'DoctrineMigrations\\Version20261014000000']);
            try {
                $backup = $engine->create();
                self::assertSame('20261025000000', $engine->verify($backup['path'])['schema_version']);
            } finally {
                $database->update('doctrine_migration_versions', ['executed_at' => $oldTimestamp], ['version' => 'DoctrineMigrations\\Version20261014000000']);
            }
            self::assertSame('emailer-light-backup-v1', $engine->verify($backup['path'])['format']);
            $newMigration = $database->fetchAssociative('SELECT * FROM doctrine_migration_versions WHERE version = ?', ['DoctrineMigrations\\Version20261025000000']);
            self::assertIsArray($newMigration);
            $database->delete('doctrine_migration_versions', ['version' => 'DoctrineMigrations\\Version20261025000000']);
            try {
                $oldSchemaBackup = $engine->create();
            } finally {
                $database->insert('doctrine_migration_versions', $newMigration);
            }
            $database->update('delivery_runtime', ['accepting_work' => 0, 'active_claims' => 0], ['singleton_key' => '1']);
            $runtimeBefore = $database->fetchAllAssociative('SELECT * FROM delivery_runtime');
            $recordsBefore = $database->fetchAllAssociative('SELECT * FROM backup_record ORDER BY id');
            try {
                $engine->restore($oldSchemaBackup['path']);
                self::fail('Cross-schema row-only restore was accepted.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('different database schema', $failure->getMessage());
            }
            self::assertSame($runtimeBefore, $database->fetchAllAssociative('SELECT * FROM delivery_runtime'));
            self::assertSame($recordsBefore, $database->fetchAllAssociative('SELECT * FROM backup_record ORDER BY id'));
            self::assertSame('20261014000000', $engine->verify($oldSchemaBackup['path'])['schema_version']);
            $ledgerCount = $database->fetchOne('SELECT COUNT(*) FROM doctrine_migration_versions');
            self::assertIsNumeric($ledgerCount);
            self::assertSame(2, (int) $ledgerCount);
            $database->delete('suppression', ['id' => $suppressionId]);
            $database->update('delivery_runtime', ['accepting_work' => 0, 'active_claims' => 0], ['singleton_key' => '1']);
            $engine->restore($backup['path']);
            self::assertSame('backup@example.test', $database->fetchOne('SELECT email_canonical FROM suppression WHERE id = ?', [$suppressionId]));
            self::assertSame('backup-recipient@example.test', $database->fetchOne('SELECT email_canonical FROM campaign_audience_recipient WHERE campaign_id = ? AND recipient_id = ?', [$campaignId, $recipientId]));
            self::assertSame('accepted', $database->fetchOne('SELECT state FROM campaign_recipient WHERE id = ?', [$deliveryId]));
            self::assertSame('emailer-light', $database->fetchOne("SELECT product_id FROM product_identity WHERE singleton_key = '1'"));
            $accepting = $database->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key = '1'");
            self::assertIsNumeric($accepting);
            self::assertSame(0, (int) $accepting);
        } finally {
            if (isset($oldTimestamp)) {
                $database->update('doctrine_migration_versions', ['executed_at' => $oldTimestamp], ['version' => 'DoctrineMigrations\\Version20261014000000']);
            }
            $database->delete('campaign_recipient', ['id' => $deliveryId]);
            $database->delete('campaign_audience_snapshot', ['campaign_id' => $campaignId]);
            $database->delete('campaign_audience_recipient', ['campaign_id' => $campaignId]);
            $database->delete('campaign', ['id' => $campaignId]);
            $database->update('email_template', ['published_version_id' => null], ['id' => $templateId]);
            $database->delete('template_version', ['id' => $versionId]);
            $database->delete('email_template', ['id' => $templateId]);
            $database->delete('app_user', ['id' => $administratorId]);
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
