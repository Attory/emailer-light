<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Upgrade\Presentation\Cli\RipePreflightCommand;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class RipePreflightTest extends KernelTestCase
{
    public function testQuiescedSyntheticInstallationPassesPreflightWithoutMutation(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $database->beginTransaction();
        $private = sys_get_temp_dir().'/light-preflight-'.bin2hex(random_bytes(8));
        mkdir($private, 0o700);
        $key = $private.'/master.key';
        file_put_contents($key, str_repeat('k', 32));
        chmod($key, 0o600);
        file_put_contents($private.'/install.lock', json_encode(['version' => 1, 'product_id' => 'emailer-light', 'product_version' => '1.2.6', 'schema_version' => '20261025000000', 'upgrade_contract_version' => '1.0', 'installation_uuid' => '01990101-0000-7000-8000-000000000001'], JSON_THROW_ON_ERROR));
        chmod($private.'/install.lock', 0o600);
        try {
            $id = '01990101-0000-7000-8000-000000000001';
            $now = '2026-01-01 00:00:00.000000';
            $database->insert('app_user', ['id' => $id, 'singleton_key' => '1', 'login_identifier' => 'admin@example.test', 'password_hash' => password_hash('synthetic-only-long-password', PASSWORD_ARGON2ID), 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
            $database->update('installation_state', ['installation_uuid' => $id, 'completed_at' => $now], ['singleton_key' => '1']);
            $database->update('product_identity', ['installation_uuid' => $id, 'product_version' => '1.2.6', 'schema_version' => '20261025000000'], ['singleton_key' => '1']);
            $database->update('delivery_runtime', ['accepting_work' => 0], ['singleton_key' => '1']);
            $database->insert('suppression', ['id' => '01990101-0000-7000-8000-000000000082', 'email_canonical' => 'Jane.Doe@example.test', 'reason' => 'unsubscribe', 'source' => 'synthetic', 'created_at' => $now]);
            $before = $database->fetchAllAssociative('SELECT * FROM product_identity');
            $tester = new CommandTester(new RipePreflightCommand($database, \dirname(__DIR__, 3), $private, $key));
            $status = $tester->execute(['--json' => true]);
            self::assertSame(0, $status, $tester->getDisplay());
            $report = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertSame('ready_for_future_ripe_installer', $report['status']);
            self::assertSame([], $report['findings']);
            self::assertSame($before, $database->fetchAllAssociative('SELECT * FROM product_identity'));
        } finally {
            $database->rollBack();
            unlink($private.'/install.lock');
            unlink($key);
            rmdir($private);
            self::ensureKernelShutdown();
        }
    }

    public function testPreflightIsReadOnlyAndRedactsRecipientData(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $database->beginTransaction();
        try {
            $database->insert('suppression', ['id' => '01990101-0000-7000-8000-000000000080', 'email_canonical' => 'private-synthetic@example.test', 'reason' => 'manual', 'source' => 'fixture', 'created_at' => '2026-01-01 00:00:00.000000']);
            $before = $database->fetchAllAssociative('SELECT id, email_canonical, reason, source, created_at FROM suppression ORDER BY id');
            $auditCount = $database->fetchOne('SELECT COUNT(*) FROM audit_event');
            $tester = new CommandTester(new RipePreflightCommand($database, \dirname(__DIR__, 3), '/tmp/nonexistent-light-private', '/tmp/nonexistent-light-key'));
            self::assertSame(3, $tester->execute(['--json' => true]));
            $output = $tester->getDisplay();
            self::assertStringNotContainsString('private-synthetic@example.test', $output);
            self::assertStringNotContainsString('password', $output);
            $report = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
            self::assertIsArray($report);
            self::assertSame('emailer-light', $report['product']);
            self::assertSame('unsafe', $report['status']);
            self::assertIsArray($report['findings']);
            self::assertContains('filesystem_prerequisite_failed', $report['findings']);
            self::assertSame($before, $database->fetchAllAssociative('SELECT id, email_canonical, reason, source, created_at FROM suppression ORDER BY id'));
            self::assertSame($auditCount, $database->fetchOne('SELECT COUNT(*) FROM audit_event'));
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
        }
    }
}
