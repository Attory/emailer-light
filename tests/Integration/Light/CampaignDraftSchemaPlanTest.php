<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Release\Application\CampaignDraftSchemaPlan;
use App\Module\Release\Application\NativeSchemaUpdate;
use App\Module\Release\Application\NativeVersionBinding;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CampaignDraftSchemaPlanTest extends KernelTestCase
{
    private Connection $database;
    private string $proofDirectory;
    private ?string $previousRuntime = null;
    /** @var array<string, mixed> */
    private array $ledger;
    /** @var array<string, mixed> */
    private array $identity;

    protected function setUp(): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('Root-owned signed proof custody is exercised by the separate root gate.');
        }
        $this->proofDirectory = sys_get_temp_dir().'/emailer-draft-plan-'.bin2hex(random_bytes(8));
        mkdir($this->proofDirectory, 0o700);
        mkdir($this->proofDirectory.'/config', 0o700);
        mkdir($this->proofDirectory.'/config/upgrade', 0o700);
        foreach (['native-schema-1.0.0.json', 'native-schema-1.2.6.json'] as $file) {
            copy(\dirname(__DIR__, 3).'/config/upgrade/'.$file, $this->proofDirectory.'/config/upgrade/'.$file);
            chmod($this->proofDirectory.'/config/upgrade/'.$file, 0o444);
        }
        $this->previousRuntime = isset($_SERVER['EMAILER_RUNTIME_DIR']) && \is_string($_SERVER['EMAILER_RUNTIME_DIR']) ? $_SERVER['EMAILER_RUNTIME_DIR'] : null;
        $_SERVER['EMAILER_RUNTIME_DIR'] = $this->proofDirectory.'/runtime';
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;
        $ledger = $database->fetchAssociative('SELECT * FROM doctrine_migration_versions WHERE version = ?', [CampaignDraftSchemaPlan::MIGRATION]);
        $identity = $database->fetchAssociative("SELECT * FROM product_identity WHERE singleton_key='1'");
        self::assertIsArray($ledger);
        self::assertIsArray($identity);
        $this->ledger = $ledger;
        $this->identity = $identity;
        $missing = $database->fetchOne('SELECT COUNT(*) FROM campaign WHERE template_version_id IS NULL');
        self::assertIsNumeric($missing);
        self::assertSame(0, (int) $missing);
        $database->executeStatement(CampaignDraftSchemaPlan::INVERSE);
        $database->delete('doctrine_migration_versions', ['version' => CampaignDraftSchemaPlan::MIGRATION]);
        $database->update('product_identity', ['product_version' => '1.2.5', 'schema_version' => CampaignDraftSchemaPlan::SOURCE_SCHEMA], ['singleton_key' => '1']);
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database, $this->ledger, $this->identity)) {
                $this->database->executeStatement(CampaignDraftSchemaPlan::FORWARD);
                $this->database->delete('doctrine_migration_versions', ['version' => CampaignDraftSchemaPlan::MIGRATION]);
                $this->database->insert('doctrine_migration_versions', $this->ledger);
                $this->database->update('product_identity', $this->identity, ['singleton_key' => '1']);
            }
        } finally {
            self::ensureKernelShutdown();
            if (isset($this->proofDirectory)) {
                if (null === $this->previousRuntime) {
                    unset($_SERVER['EMAILER_RUNTIME_DIR']);
                } else {
                    $_SERVER['EMAILER_RUNTIME_DIR'] = $this->previousRuntime;
                }
                (new \Symfony\Component\Filesystem\Filesystem())->remove($this->proofDirectory);
            }
            parent::tearDown();
        }
    }

    /** @return array{definitions: array<string, array<string, string>>, triggers: list<array<string, mixed>>, views: list<array<string, mixed>>, ledger: list<array<string, mixed>>} */
    private function parts(): array
    {
        $updater = self::getContainer()->get(NativeSchemaUpdate::class);
        $parts = (new \ReflectionMethod(NativeSchemaUpdate::class, 'schemaParts'))->invoke($updater);
        self::assertIsArray($parts);

        $definitions = [];
        foreach (self::map($parts['definitions'] ?? null) as $table => $row) {
            $definition = [];
            foreach (self::map($row) as $column => $value) {
                self::assertIsString($value);
                $definition[$column] = $value;
            }
            $definitions[$table] = $definition;
        }

        return ['definitions' => $definitions, 'triggers' => self::rows($parts['triggers'] ?? null), 'views' => self::rows($parts['views'] ?? null), 'ledger' => self::rows($parts['ledger'] ?? null)];
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $row) {
            $result[] = self::map($row);
        }

        return $result;
    }

    private function dataset(): mixed
    {
        return (new \ReflectionMethod(NativeSchemaUpdate::class, 'data'))->invoke(self::getContainer()->get(NativeSchemaUpdate::class), new NativeVersionBinding('1.2.5', '1.2.6'), true);
    }

    public function testPublicationIntentRejectsSourceDriftAndAdmitsOnlyRealCrashTuples(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $parts = $this->parts();
        $plan = $migration->prepare($parts);
        $sourceLock = json_encode(['product_version' => '1.2.5', 'schema_version' => CampaignDraftSchemaPlan::SOURCE_SCHEMA], JSON_THROW_ON_ERROR);
        $targetLock = json_encode(['product_version' => '1.2.6', 'schema_version' => CampaignDraftSchemaPlan::TARGET_SCHEMA], JSON_THROW_ON_ERROR);
        $migration->assertLock($plan, $sourceLock);
        foreach (['source', 'forward_intent'] as $phase) {
            $plan['state'] = $phase;
            try {
                $migration->assertLock($plan, $targetLock);
                self::fail('A future publication lock was accepted before publication intent.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('outside the recorded', $failure->getMessage());
            }
            $this->database->update('product_identity', ['product_version' => '1.2.6'], ['singleton_key' => '1']);
            try {
                $migration->assertState($plan, CampaignDraftSchemaPlan::hash($parts));
                self::fail('Source-schema identity drift was normalised away.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('outside the recorded', $failure->getMessage());
            } finally {
                $this->database->update('product_identity', ['product_version' => '1.2.5'], ['singleton_key' => '1']);
            }
        }
        $plan['state'] = 'source';
        $plan = $migration->forward($plan, static function (array $state): void {});
        $targetHash = CampaignDraftSchemaPlan::hash($this->parts());
        $migration->assertState($plan, $targetHash);
        $migration->assertLock($plan, $sourceLock);
        $plan['publication'] = 'forward_intent';
        $migration->assertState($plan, $targetHash);
        $migration->assertLock($plan, $sourceLock);
        $this->database->update('product_identity', ['product_version' => '1.2.6'], ['singleton_key' => '1']);
        $migration->assertState($plan, $targetHash);
        $migration->assertLock($plan, $sourceLock); // crash after database version, before file
        $migration->assertLock($plan, $targetLock); // crash after the atomic file publication
        $plan['reverse_target_lock'] = true;
        $plan = $migration->reverse($plan, static function (array $state): void {});
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        $migration->assertLock($plan, $targetLock); // inverse DDL complete; source metadata still pending
        $this->database->update('product_identity', ['product_version' => '1.2.5'], ['singleton_key' => '1']);
        $migration->assertLock($plan, $targetLock); // crash after source database version, before source file
        $migration->assertLock($plan, $sourceLock);
        $plan['publication'] = 'restored';
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        $migration->assertLock($plan, $sourceLock);
        self::assertSame($parts, $this->parts());
    }

    public function testPackageInventoryAdmitsOnlyTheExactNewMigrationWithoutMutation(): void
    {
        mkdir($this->proofDirectory.'/migrations', 0o700);
        copy(\dirname(__DIR__, 3).'/'.CampaignDraftSchemaPlan::FILE, $this->proofDirectory.'/'.CampaignDraftSchemaPlan::FILE);
        chmod($this->proofDirectory.'/'.CampaignDraftSchemaPlan::FILE, 0o444);
        $old = ['migrations/Version20261014000000.php' => str_repeat('a', 64)];
        $source = ['schema_version' => CampaignDraftSchemaPlan::SOURCE_SCHEMA, 'files' => $old];
        $target = ['version' => '1.2.6', 'schema_version' => CampaignDraftSchemaPlan::TARGET_SCHEMA, 'files' => [...$old, CampaignDraftSchemaPlan::FILE => hash_file('sha256', $this->proofDirectory.'/'.CampaignDraftSchemaPlan::FILE)]];
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $before = $this->parts();
        $migration->assertPackages($source, $target);
        $cases = [];
        $changed = $target;
        $changed['files']['migrations/Version20261014000000.php'] = str_repeat('b', 64);
        $cases[] = $changed;
        $changed = $target;
        $changed['files']['migrations/Unexpected.php'] = str_repeat('c', 64);
        $cases[] = $changed;
        $changed = $target;
        unset($changed['files']['migrations/Version20261014000000.php']);
        $cases[] = $changed;
        $changed = $target;
        $changed['files'][CampaignDraftSchemaPlan::FILE] = str_repeat('d', 64);
        $cases[] = $changed;
        $changed = $target;
        $changed['schema_version'] = CampaignDraftSchemaPlan::SOURCE_SCHEMA;
        $cases[] = $changed;
        foreach ($cases as $invalid) {
            try {
                $migration->assertPackages($source, $invalid);
                self::fail('An unauthorised migration inventory was accepted.');
            } catch (\RuntimeException $failure) {
                self::assertNotSame('', $failure->getMessage());
            }
            self::assertSame($before, $this->parts());
        }
    }

    public function testExactForwardHashesMatchRealDdlLedgerTypesAndFrozenDataset(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $parts = $this->parts();
        $plan = $migration->prepare($parts);
        $before = $this->dataset();
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($parts));
        $states = [];
        $plan = $migration->forward($plan, static function (array $state) use (&$states): void { $states[] = $state['state']; });
        self::assertSame(['forward_intent', 'target'], $states);
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        self::assertSame($before, $this->dataset());
        $states = [];
        $plan = $migration->reverse($plan, static function (array $state) use (&$states): void { $states[] = $state['state']; });
        self::assertSame(['reverse_intent', 'source_recovered'], $states);
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        self::assertSame($parts, $this->parts());
        self::assertSame($before, $this->dataset());
    }

    public function testCrashAfterAlterBeforeLedgerCanRecoverExactlyAndWrongPhaseCannotAcceptIt(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $parts = $this->parts();
        $plan = $migration->prepare($parts);
        $this->database->executeStatement(CampaignDraftSchemaPlan::FORWARD);
        try {
            $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
            self::fail('DDL without its durable intent was accepted.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('outside the recorded migration phase', $failure->getMessage());
        }
        $plan['state'] = 'forward_intent';
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        $plan = $migration->reverse($plan, static function (array $state): void {});
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        self::assertSame($parts, $this->parts());
    }

    public function testLedgerDriftCannotPassKnownDdl(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $plan = $migration->prepare($this->parts());
        $plan = $migration->forward($plan, static function (array $state): void {});
        $this->database->executeStatement('UPDATE doctrine_migration_versions SET execution_time=execution_time+1 WHERE version=?', [CampaignDraftSchemaPlan::MIGRATION]);
        $this->expectException(\RuntimeException::class);
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
    }

    public function testInverseInterruptionAfterLedgerRemovalResumesWithoutChangingSourceHistory(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $parts = $this->parts();
        $plan = $migration->forward($migration->prepare($parts), static function (array $state): void {});
        $plan['state'] = 'reverse_intent';
        $this->database->transactional(function (): void {
            $this->database->delete('doctrine_migration_versions', ['version' => CampaignDraftSchemaPlan::MIGRATION]);
            $this->database->update('product_identity', ['schema_version' => CampaignDraftSchemaPlan::SOURCE_SCHEMA], ['singleton_key' => '1']);
        });
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        $plan = $migration->reverse($plan, static function (array $state): void {});
        $migration->assertState($plan, CampaignDraftSchemaPlan::hash($this->parts()));
        self::assertSame($parts, $this->parts());
    }

    public function testNullDraftAndDatasetDriftPreventInverseWithoutAnyMetadataMutation(): void
    {
        $migration = new CampaignDraftSchemaPlan($this->database, $this->proofDirectory);
        $before = $this->dataset();
        $parts = $this->parts();
        $plan = $migration->forward($migration->prepare($parts), static function (array $state): void {});
        $actor = $this->database->fetchOne('SELECT id FROM app_user LIMIT 1');
        $createdActor = false === $actor;
        if ($createdActor) {
            $actor = \App\Shared\Identity::uuidV7();
            $this->database->insert('app_user', ['id' => $actor, 'singleton_key' => '1', 'login_identifier' => 'synthetic-null-draft@example.test', 'password_hash' => 'not-a-login-hash', 'active' => 1, 'created_at' => \App\Shared\Identity::now(), 'updated_at' => \App\Shared\Identity::now(), 'password_changed_at' => \App\Shared\Identity::now()]);
        }
        $id = \App\Shared\Identity::uuidV7();
        try {
            $this->database->insert('campaign', ['id' => $id, 'name' => 'Synthetic new incomplete draft', 'template_version_id' => null, 'state' => 'draft', 'created_by' => $actor, 'created_at' => \App\Shared\Identity::now(), 'updated_at' => \App\Shared\Identity::now()]);
            self::assertNotSame($before, $this->dataset());
            $schema = $this->parts();
            try {
                $migration->reverse($plan, static function (array $state): void {});
                self::fail('New NULL data was coerced or removed during inverse recovery.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('Nothing was removed or coerced', $failure->getMessage());
            }
            self::assertSame($schema, $this->parts());
            self::assertNull($this->database->fetchOne('SELECT template_version_id FROM campaign WHERE id=?', [$id]));
        } finally {
            $this->database->delete('campaign', ['id' => $id]);
            if ($createdActor) {
                $this->database->delete('app_user', ['id' => $actor]);
            }
        }
        self::assertSame($before, $this->dataset());
        $migration->reverse($plan, static function (array $state): void {});
        self::assertSame($parts, $this->parts());
    }
}
