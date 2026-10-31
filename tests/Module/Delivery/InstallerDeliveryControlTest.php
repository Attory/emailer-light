<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Module\Release\Application\InstallerDeliveryControl;
use App\Shared\DeliveryControlFiles;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\TestCase;

final class InstallerDeliveryControlTest extends TestCase
{
    private const INSTALLATION = '01900000-0000-7000-8000-000000000001';
    private const OPERATION = '01900000-0000-7000-8000-000000000002';
    private const OTHER = '01900000-0000-7000-8000-000000000003';
    private string $directory;
    private string $database;
    private Connection $admin;
    private Connection $db;

    protected function setUp(): void
    {
        if (0 !== posix_geteuid() || '1' !== getenv('EMAILER_CONTROL_DATABASE_FIXTURE')) {
            self::markTestSkipped('Needs the isolated root/MariaDB control fixture.');
        }
        $dsn = getenv('DATABASE_URL');
        if (!\is_string($dsn)) {
            throw new \RuntimeException('Synthetic control database fixture is unavailable.');
        }
        $params = (new DsnParser(['mysql' => 'pdo_mysql']))->parse($dsn);
        $this->admin = DriverManager::getConnection($params);
        $this->database = 'control_'.bin2hex(random_bytes(8));
        $this->admin->executeStatement('CREATE DATABASE `'.$this->database.'`');
        $params['dbname'] = $this->database;
        $this->db = DriverManager::getConnection($params);
        $this->db->executeStatement('CREATE TABLE installation_state(singleton_key CHAR(1) PRIMARY KEY, installation_uuid CHAR(36) NOT NULL)');
        $this->db->executeStatement('INSERT INTO installation_state VALUES(?,?)', ['1', self::INSTALLATION]);
        $this->db->executeStatement('CREATE TABLE delivery_runtime(singleton_key CHAR(1) PRIMARY KEY, accepting_work TINYINT NOT NULL,active_claims INT NOT NULL,updated_at DATETIME(6) NOT NULL)');
        $this->db->executeStatement("INSERT INTO delivery_runtime VALUES('1',1,0,UTC_TIMESTAMP(6))");
        $this->db->executeStatement('CREATE TABLE release_operation(id CHAR(36) PRIMARY KEY,operation VARCHAR(16),status VARCHAR(16),started_at DATETIME(6))');
        $this->directory = '/run/emailer-control-db-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o755);
        mkdir($this->directory.'/private', 0o700);
        $this->proof(false);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if (isset($this->admin, $this->database)) {
            $this->admin->executeStatement('DROP DATABASE `'.$this->database.'`');
            $this->admin->close();
        }
        if (isset($this->directory)) {
            $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($paths as $path) {
                if (!$path instanceof \SplFileInfo) {
                    throw new \LogicException('Unexpected fixture entry.');
                }
                if ($path->isDir() && !$path->isLink()) {
                    rmdir($path->getPathname());
                } else {
                    unlink($path->getPathname());
                }
            }
            rmdir($this->directory);
        }
    }

    public function testSuccessfulCompletionAutomaticallyRestoresEnabledIntentOnce(): void
    {
        $control = $this->begin();
        $this->proof(true);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        self::assertSame(1, $this->accepting());
        self::assertSame([], $this->files()->status()['holds']);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        self::assertSame(1, $this->accepting());
    }

    public function testNewOwnerPauseSurvivesSuccessfulCompletion(): void
    {
        $control = $this->begin();
        $this->files()->pause();
        $before = $this->files()->status()['generation'];
        $this->proof(true);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        self::assertSame(0, $this->accepting());
        self::assertSame('paused', $this->files()->status()['owner']);
        self::assertSame($before, $this->files()->status()['generation']);
        self::assertSame([], $this->files()->status()['holds']);
    }

    public function testOtherHoldAndUnknownLegacyPauseRemainIndependent(): void
    {
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');
        $control = $this->begin();
        $this->files()->hold(self::OTHER, 'manual', ['parent_operation' => self::OPERATION]);
        $this->proof(true);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        self::assertSame('unknown', $this->files()->status()['owner']);
        self::assertSame([self::OTHER], array_column($this->files()->status()['holds'], 'operation_id'));
        self::assertSame(0, $this->accepting());
    }

    public function testUnfinishedHostCannotReleaseItsFence(): void
    {
        $control = $this->begin();
        try {
            $control->completeHost(self::OPERATION, $this->directory.'/management.json');
            self::fail('Unfinished host completion must fail closed.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->accepting());
            self::assertCount(1, $this->files()->status()['holds']);
        }
    }

    public function testRecordedPauseWithStaleEnabledProjectionCanBeExplicitlyResumed(): void
    {
        $this->db->executeStatement('ALTER TABLE delivery_runtime ADD schema_version INT NOT NULL DEFAULT 1');
        $this->db->executeStatement('ALTER TABLE release_operation ADD detail_code VARCHAR(32)');
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        // A pause recorded while maintenance owns the DB lock is authoritative,
        // even if its legacy projection is still 1 when maintenance ends.
        $files->pause();
        self::assertSame(1, $this->accepting());
        $gate = new class implements \App\Module\Delivery\Application\Contract\SendingGate {
            public function assertReady(): void
            {
            }

            public function assertMaintenanceReady(): void
            {
            }
        };
        $maintenance = new \App\Shared\MaintenanceOperationLock(new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($this->directory)));
        $intent = new \App\Module\Delivery\Infrastructure\FilesystemDeliveryControl($this->db, $this->directory.'/private');
        (new \App\Module\Delivery\Application\DeliveryResumer($this->db, $gate, $maintenance, control: $intent))->resume();
        self::assertSame('enabled', $files->status()['owner']);
        self::assertSame(1, $this->accepting());
    }

    public function testUncertainOutcomeIsASeparateReviewRequirementAfterMaintenance(): void
    {
        $control = $this->begin();
        $this->proof(true);
        $path = $this->directory.'/management.json';
        $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $record['delivery_review_operation_id'] = self::OTHER;
        $record['delivery_review_count'] = 1;
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        $control->retainDeliveryReview(self::OPERATION, $path);
        $control->completeHost(self::OPERATION, $path);
        self::assertSame('released', $this->files()->operationState(self::OPERATION)['phase']);
        self::assertSame([self::OTHER], array_column($this->files()->status()['holds'], 'operation_id'));
        self::assertSame(0, $this->accepting());
        try {
            $control->acknowledgeDeliveryReview(self::OPERATION, $path);
            self::fail('Review must require explicit acknowledgement.');
        } catch (\RuntimeException) {
            self::assertCount(1, $this->files()->status()['holds']);
        }
        $record['control_release_completed'] = true;
        $record['delivery_review_acknowledged'] = true;
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        $control->acknowledgeDeliveryReview(self::OPERATION, $path);
        self::assertSame([], $this->files()->status()['holds']);
        self::assertSame(0, $this->accepting()); // Normal explicit Resume still required.
    }

    public function testPublicationCrashBarrierAndCompletionNeverRetryTerminalTestWork(): void
    {
        $control = $this->begin();
        $this->db->executeStatement('CREATE TABLE campaign(id CHAR(36) PRIMARY KEY,operation_version INT,state VARCHAR(16),name VARCHAR(64),template_version_id CHAR(36),rsvp_enabled TINYINT)');
        $this->db->executeStatement('CREATE TABLE campaign_test_delivery(id CHAR(36) PRIMARY KEY,campaign_id CHAR(36),state VARCHAR(16),recipient_address VARCHAR(255),draft_version INT)');
        $this->db->executeStatement("INSERT INTO campaign VALUES(?,1,'draft','Synthetic',NULL,0)", [self::OPERATION]);
        $this->db->executeStatement("INSERT INTO campaign_test_delivery VALUES(?,?,'failed','recipient@example.test',1)", [self::OTHER, self::OPERATION]);
        $gateway = $this->createMock(\App\Module\Delivery\Application\Contract\CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchTestAfter')->with(self::OTHER, 30_000);
        $gateway->expects(self::never())->method('dispatchTest');
        $transport = $this->createMock(\App\Module\Delivery\Application\Contract\DeliveryTransport::class);
        $transport->expects(self::never())->method('send');
        $settings = $this->createMock(\App\Module\Configuration\Application\Contract\DeliveryConfiguration::class);
        $settings->expects(self::never())->method('current');
        $handler = new \App\Module\Delivery\Application\DeliverCampaignTestHandler($this->db, self::createStub(\App\Module\Templates\Application\Contract\PublishedTemplateProvider::class), $settings, $transport, self::createStub(\App\Module\Suppression\Application\Contract\SuppressionLookup::class), self::createStub(\App\Module\Delivery\Application\Contract\SendingGate::class), $gateway, new \App\Module\Delivery\Infrastructure\FilesystemDeliveryControl($this->db, $this->directory.'/private'));
        // The dangerous crash boundary is a committed accepting=1 projection
        // before the durable file release. The file must still prevent claims.
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=1');
        $message = new \App\Module\Delivery\Application\Message\DeliverCampaignTest(self::OTHER);
        $handler($message);
        self::assertSame('failed', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
        $this->proof(true);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        $handler($message);
        self::assertSame('failed', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
        $this->db->executeStatement("UPDATE campaign_test_delivery SET state='accepted'");
        $handler($message);
        self::assertSame('accepted', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
        $count = $this->db->fetchOne('SELECT COUNT(*) FROM campaign_test_delivery');
        self::assertTrue(is_numeric($count));
        self::assertSame(1, (int) $count);
    }

    public function testLegacyRetryPreservesOnlyProvenIntentAndNeverErasesANewerPause(): void
    {
        $path = $this->directory.'/management.json';
        $record = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($record);
        $record['control_legacy_source'] = true;
        $record['control_capture_invocation'] = 'first';
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        $control = new InstallerDeliveryControl($this->db, $this->directory.'/private');
        $control->beginHost(self::OPERATION, $path, 1000, 1000, 'first');
        self::assertSame('enabled', $this->files()->status()['owner']);
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');
        // A retained source-account fence proves an old Pause could not have
        // acknowledged new intent between planned Upgrade and Accept/Finish.
        $control->beginHost(self::OPERATION, $path, 1000, 1000, 'planned-finish', true);
        self::assertSame('enabled', $this->files()->status()['owner']);
        // After unclean recovery with old access available, the old code could
        // have acknowledged Pause. Do not automatically assume enabled intent.
        $control->beginHost(self::OPERATION, $path, 1000, 1000, 'unclean-retry', false);
        self::assertSame('unknown', $this->files()->status()['owner']);
        $this->files()->pause();
        $before = $this->files()->status();
        $control->beginHost(self::OPERATION, $path, 1000, 1000, 'another-retry', false);
        self::assertSame($before, $this->files()->status());
    }

    public function testFreshBootstrapHoldsBeforeServicesAndTerminalCompletionDoesNotTouchLaterWork(): void
    {
        $path = $this->directory.'/management.json';
        $record = ['control_operation_id' => self::OPERATION, 'control_installation_uuid' => self::INSTALLATION, 'control_private_directory' => $this->directory.'/private', 'completed' => ['Creating your administrator account']];
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        $control = new InstallerDeliveryControl($this->db, $this->directory.'/private');
        $mask = umask(0o077);
        try {
            $control->beginFresh(self::OPERATION, $path, 1000, 1000);
        } finally {
            umask($mask);
        }
        self::assertSame(0, $this->accepting());
        self::assertCount(1, $this->files()->status()['holds']);
        $record['completed'][] = 'Checking application health and initial backup';
        $record['control_runtime_verified'] = true;
        file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
        $this->files()->pause();
        $control->completeFresh(self::OPERATION, $path);
        self::assertSame(0, $this->accepting());
        self::assertSame([], $this->files()->status()['holds']);
        $this->db->executeStatement('UPDATE delivery_runtime SET active_claims=2');
        $control->completeFresh(self::OPERATION, $path);
        self::assertSame(0, $this->accepting());
        self::assertSame('paused', $this->files()->status()['owner']);
    }

    /** @return iterable<string, array{bool}> */
    public static function recoveryProvenance(): iterable
    {
        yield 'matching verified source' => [true];
        yield 'different recovered source stays held' => [false];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('recoveryProvenance')]
    public function testSuccessfulStandaloneRetryRetiresOnlyItsArchivedRecoveredLineage(bool $matching): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $base = $this->directory.'/native-updates';
        mkdir($base, 0o700);
        $path = $base.'/light-1.2.8-to-1.2.9.json';
        $common = ['delivery_control_source_aware' => true, 'format' => 'emailer-light-native-update-v1', 'identity' => ['installation_uuid' => self::INSTALLATION], 'binding' => str_repeat('a', 64), 'source_sha256' => str_repeat('b', 64), 'target_sha256' => str_repeat('c', 64), 'fence_restored' => true];
        file_put_contents($base.'/recovered-'.self::OTHER.'.json', json_encode([...$common, 'id' => self::OTHER, 'phase' => 'recovered', 'previous_operation_id' => null], JSON_THROW_ON_ERROR));
        chmod($base.'/recovered-'.self::OTHER.'.json', 0o600);
        file_put_contents($path, json_encode([...$common, 'id' => self::OPERATION, 'phase' => 'completed', 'control_runtime_verified' => true, 'previous_operation_id' => self::OTHER], JSON_THROW_ON_ERROR));
        chmod($path, 0o600);
        foreach ([self::OTHER => 'recovered', self::OPERATION => 'handoff'] as $id => $phase) {
            $files->hold($id, 'native', ['proof_path' => $path]);
            $files->phase($id, $phase);
        }
        $this->db->insert('release_operation', ['id' => self::OPERATION, 'operation' => 'update', 'status' => 'succeeded', 'started_at' => '2026-01-01 00:00:00']);
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');
        if (!$matching) {
            $predecessor = $base.'/recovered-'.self::OTHER.'.json';
            $invalid = json_decode((string) file_get_contents($predecessor), true, flags: JSON_THROW_ON_ERROR);
            if (!\is_array($invalid)) {
                throw new \LogicException('Invalid recovery fixture.');
            }
            $invalid['source_sha256'] = str_repeat('d', 64);
            file_put_contents($predecessor, json_encode($invalid, JSON_THROW_ON_ERROR));
            try {
                (new InstallerDeliveryControl($this->db, $this->directory.'/private'))->completeLightNative(self::OPERATION, $path);
                self::fail('Unrelated recovered source must not be retired.');
            } catch (\RuntimeException) {
                self::assertSame(0, $this->accepting());
                self::assertCount(2, $files->status()['holds']);
                self::assertSame('recovered', $files->operationState(self::OTHER)['phase']);
            }

            return;
        }
        (new InstallerDeliveryControl($this->db, $this->directory.'/private'))->completeLightNative(self::OPERATION, $path);
        self::assertSame([], $files->status()['holds']);
        self::assertSame('released', $files->operationState(self::OTHER)['phase']);
        self::assertSame(1, $this->accepting());
        // Exercise native receipt republication after the irreversible release
        // boundary without replaying an entire synthetic installation here.
        $nativeType = new \ReflectionClass(\App\Module\Release\Application\NativeSchemaUpdate::class);
        $native = $nativeType->newInstanceWithoutConstructor();
        $nativeType->getProperty('privateDirectory')->setValue($native, $this->directory.'/private');
        $terminal = \App\Module\Release\Application\NativeUpdateJournal::read($path);
        $terminal['delivery_control_owned'] = true;
        $nativeType->getMethod('save')->invoke($native, $path, $terminal);
        self::assertSame('released', $files->operationState(self::OPERATION)['phase']);
        self::assertSame([], $files->status()['holds']);
    }

    public function testAnExistingEligibleTestIsAdmittedOnceAfterOwnedCompletion(): void
    {
        $control = $this->begin();
        $this->db->executeStatement('ALTER TABLE delivery_runtime ADD schema_version INT NOT NULL DEFAULT 1');
        $this->db->executeStatement('CREATE TABLE campaign(id CHAR(36) PRIMARY KEY,operation_version INT,state VARCHAR(16),name VARCHAR(64),template_version_id CHAR(36),rsvp_enabled TINYINT)');
        $this->db->executeStatement('CREATE TABLE campaign_test_delivery(id CHAR(36) PRIMARY KEY,campaign_id CHAR(36),state VARCHAR(16),recipient_address VARCHAR(255),draft_version INT,accepted_at DATETIME(6) NULL,failure_class VARCHAR(32) NULL)');
        $this->db->executeStatement("INSERT INTO campaign VALUES(?,1,'draft','Synthetic',?,0)", [self::OPERATION, self::INSTALLATION]);
        $this->db->executeStatement("INSERT INTO campaign_test_delivery(id,campaign_id,state,recipient_address,draft_version) VALUES(?,?,'pending','recipient@example.test',1)", [self::OTHER, self::OPERATION]);
        $gateway = $this->createMock(\App\Module\Delivery\Application\Contract\CampaignDeliveryGateway::class);
        $gateway->expects(self::once())->method('dispatchTestAfter')->with(self::OTHER, 30_000);
        $gateway->expects(self::never())->method('dispatchTest');
        $transport = $this->createMock(\App\Module\Delivery\Application\Contract\DeliveryTransport::class);
        $transport->expects(self::once())->method('send')->willReturn('synthetic-fake-only');
        $settings = self::createStub(\App\Module\Configuration\Application\Contract\DeliveryConfiguration::class);
        $settings->method('current')->willReturn(['installation_id' => self::INSTALLATION, 'host' => 'mailpit', 'port' => 1025, 'encryption' => 'none', 'username' => null, 'credential_id' => null, 'verify_peer' => false, 'sender_address' => 'sender@example.test', 'sender_name' => 'Synthetic', 'reply_to' => null, 'one_click_dkim_confirmed' => false, 'unsubscribe_footer' => 'Synthetic', 'open_tracking' => false, 'click_tracking' => false, 'rsvp_enabled' => false, 'rsvp_retention_days' => 90, 'rsvp_repeat_policy' => 'replace', 'messages_per_minute' => 10, 'concurrency' => 1, 'retry_limit' => 1, 'max_campaign_size' => 10]);
        $templates = self::createStub(\App\Module\Templates\Application\Contract\PublishedTemplateProvider::class);
        $templates->method('get')->willReturn(['id' => self::INSTALLATION, 'subject' => 'Synthetic', 'preheader' => null, 'html' => '<p>Synthetic</p>', 'text' => 'Synthetic', 'merge_fields' => [], 'links' => [], 'content_sha256' => hash('sha256', 'Synthetic')]);
        $handler = new \App\Module\Delivery\Application\DeliverCampaignTestHandler($this->db, $templates, $settings, $transport, self::createStub(\App\Module\Suppression\Application\Contract\SuppressionLookup::class), self::createStub(\App\Module\Delivery\Application\Contract\SendingGate::class), $gateway, new \App\Module\Delivery\Infrastructure\FilesystemDeliveryControl($this->db, $this->directory.'/private'));
        $message = new \App\Module\Delivery\Application\Message\DeliverCampaignTest(self::OTHER);
        $handler($message);
        self::assertSame('pending', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
        $this->proof(true);
        $control->completeHost(self::OPERATION, $this->directory.'/management.json');
        $handler($message);
        self::assertSame('accepted', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
        $handler($message); // Redelivery must not invoke the transport again.
        self::assertSame('accepted', $this->db->fetchOne('SELECT state FROM campaign_test_delivery'));
    }

    /** @return iterable<string, array{?bool, bool, string, int}> */
    public static function standaloneWriterIntent(): iterable
    {
        yield 'verified legacy completion restores enabled intent' => [false, false, 'enabled', 1];
        yield 'verified completion does not invent an owner pause' => [null, false, 'enabled', 1];
        yield 'continuously fenced legacy writer' => [false, true, 'enabled', 1];
        yield 'aware source preserves enabled intent' => [true, false, 'enabled', 1];
        yield 'aware source preserves pause' => [true, false, 'paused', 0];
        yield 'legacy source preserves newer pause' => [false, false, 'paused', 0];
        yield 'legacy source preserves explicit Resume generation' => [false, false, 'resumed', 1];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('standaloneWriterIntent')]
    public function testVerifiedStandaloneCompletionRetainsOwnerIntent(?bool $aware, bool $fenced, string $intent, int $expected): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        if ('paused' === $intent) {
            $files->pause();
        } elseif ('resumed' === $intent) {
            $files->pause();
            $files->enable();
        }
        $base = $this->directory.'/native-updates';
        mkdir($base, 0o700);
        $proof = $base.'/light-1.2.8-to-1.2.12.json';
        file_put_contents($proof, json_encode(['format' => 'emailer-light-native-update-v1', 'identity' => ['installation_uuid' => self::INSTALLATION], 'id' => self::OPERATION, 'phase' => 'completed', 'binding' => str_repeat('a', 64), 'source_sha256' => str_repeat('b', 64), 'target_sha256' => str_repeat('c', 64), 'fence_restored' => true, 'control_runtime_verified' => true, 'retain_source_fence' => $fenced, 'delivery_control_source_aware' => $aware], JSON_THROW_ON_ERROR));
        chmod($proof, 0o600);
        $files->hold(self::OPERATION, 'native', ['proof_path' => $proof]);
        $this->db->insert('release_operation', ['id' => self::OPERATION, 'operation' => 'update', 'status' => 'succeeded', 'started_at' => '2026-01-01 00:00:00']);
        // The temporary maintenance flag is distinct from independent owner intent.
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');
        $control = new InstallerDeliveryControl($this->db, $this->directory.'/private');
        $control->completeLightNative(self::OPERATION, $proof);
        self::assertSame($expected, $this->accepting());
        self::assertSame([], $files->status()['holds']);
        self::assertSame('released', $files->operationState(self::OPERATION)['phase']);
        if (true !== $aware && !$fenced && 'enabled' === $intent) {
            self::assertSame('enabled', $files->status()['owner']);
        }
        $files->pause();
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0,active_claims=1');
        $control->completeLightNative(self::OPERATION, $proof);
        self::assertSame(0, $this->accepting());
        self::assertSame('paused', $files->status()['owner']);
    }

    private function accepting(): int
    {
        $value = $this->db->fetchOne('SELECT accepting_work FROM delivery_runtime');
        if (!is_numeric($value)) {
            throw new \RuntimeException('Fixture delivery flag is not numeric.');
        }

        return (int) $value;
    }

    private function begin(): InstallerDeliveryControl
    {
        $control = new InstallerDeliveryControl($this->db, $this->directory.'/private');
        $mask = umask(0o077);
        try {
            $control->beginHost(self::OPERATION, $this->directory.'/management.json', 1000, 1000);
        } finally {
            umask($mask);
        }
        $this->db->executeStatement('UPDATE delivery_runtime SET accepting_work=0');

        return $control;
    }

    private function files(): DeliveryControlFiles
    {
        return new DeliveryControlFiles($this->directory.'/private', self::INSTALLATION);
    }

    private function proof(bool $complete): void
    {
        file_put_contents($this->directory.'/management.json', json_encode(['control_operation_id' => self::OPERATION, 'control_private_directory' => $this->directory.'/private', 'delivery_source_verified' => true, 'host_handoff' => ['installation_uuid' => self::INSTALLATION, 'phase' => $complete ? 'complete' : 'reserved', 'control_runtime_verified' => $complete]], JSON_THROW_ON_ERROR));
        chmod($this->directory.'/management.json', 0o600);
    }
}
