<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AdministratorPasswordManager;
use App\Module\Identity\Application\PasswordPolicy;
use App\Module\Identity\Application\SensitiveValueHasher;
use App\Module\Identity\Application\SessionRegistry;
use App\Module\Identity\Infrastructure\DoctrineAdministratorSetup;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdministratorTest extends KernelTestCase
{
    private static function numeric(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    public function testInstallerCreatesOneAdministratorAndCliRecoveryRevokesSessionsAndAudits(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $audit = self::getContainer()->get(AuditSink::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        self::assertInstanceOf(AuditSink::class, $audit);
        $database->beginTransaction();
        try {
            $lifecycle = new class implements SetupLifecycle {
                public function mutate(\Closure $operation): mixed
                {
                    return $operation();
                }

                public function finalize(\Closure $operation): mixed
                {
                    return $operation();
                }
            };
            $setup = new DoctrineAdministratorSetup($database, $hasher, $lifecycle, new PasswordPolicy());
            $oldPassword = 'synthetic-sunset-phrase-2026';
            $newPassword = 'synthetic-moonlight-phrase-2026';
            $id = $setup->create('Admin@EXAMPLE.TEST', $oldPassword);
            self::assertSame($id, $setup->create('Admin@example.test', $oldPassword));
            try {
                $setup->create('second@example.test', 'synthetic-second-phrase-2026');
                self::fail('Installer created a second administrator.');
            } catch (\RuntimeException) {
                self::assertSame(1, self::numeric($database->fetchOne('SELECT COUNT(*) FROM app_user')));
            }
            $sessions = new SessionRegistry($database, new SensitiveValueHasher('synthetic-session-key'));
            $sessions->bind('synthetic-session', $id, '127.0.0.1', 'test');
            $manager = new AdministratorPasswordManager($database, $hasher, new PasswordPolicy(), $sessions, $audit);
            $manager->recover($newPassword);
            $hash = $database->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$id]);
            self::assertIsString($hash);
            self::assertStringStartsWith('$argon2id$', $hash);
            self::assertFalse(password_verify($oldPassword, $hash));
            self::assertTrue(password_verify($newPassword, $hash));
            self::assertSame(1, self::numeric($database->fetchOne('SELECT COUNT(*) FROM user_session WHERE user_id = ? AND revoked_at IS NOT NULL', [$id])));
            self::assertSame(1, self::numeric($database->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'identity.password.cli_recovered' AND actor_user_id = ?", [$id])));
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
        }
    }
}
