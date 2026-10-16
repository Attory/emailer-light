<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\MailProbe;
use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Secrets\Application\Contract\SecretVault;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class MailCredentialReuseTest extends TestCase
{
    public function testDisplayNameEditKeepsTheEncryptedCredentialWithoutReenteringOrRevealingIt(): void
    {
        $connection = self::createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());
        $connection->method('fetchOne')->willReturn('01990101-0000-7000-8000-000000000001');
        $connection->method('fetchAssociative')->willReturn(['credential_secret_id' => 'saved-secret-identity', 'smtp_host' => 'smtp.example.test', 'smtp_username' => 'same-login']);
        $connection->expects(self::once())->method('update')->with('sender_identity', self::callback(static fn (array $values): bool => 'saved-secret-identity' === $values['credential_secret_id'] && 'Changed display name' === $values['sender_name']), ['singleton_key' => '1']);
        $vault = self::createMock(SecretVault::class);
        foreach (['replace', 'store', 'reveal'] as $method) {
            $vault->expects(self::never())->method($method);
        }
        $manager = new MailConfigurationManager($connection, self::createStub(AdministrativeAuthorization::class), $vault, self::createStub(AuditSink::class), self::createStub(MailProbe::class));
        $actor = self::createStub(AdministrativeActor::class);
        $actor->method('id')->willReturn('01990101-0000-7000-8000-000000000002');
        $manager->configureSecureSmtp($actor, 'smtp.example.test', 587, 'starttls', 'same-login', '', 'sender@example.test', 'Changed display name', '');
    }

    /** @return iterable<string, array{string, string}> */
    public static function changedProvider(): iterable
    {
        yield 'different server' => ['another-provider.example.test', 'same-login'];
        yield 'different username' => ['smtp.example.test', 'another-login'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedProvider')]
    public function testBlankPasswordCannotReuseASecretForAnotherProvider(string $host, string $username): void
    {
        $connection = self::createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());
        $connection->method('fetchOne')->willReturn('01990101-0000-7000-8000-000000000001');
        $connection->method('fetchAssociative')->willReturn(['credential_secret_id' => 'saved-secret-identity', 'smtp_host' => 'smtp.example.test', 'smtp_username' => 'same-login']);
        $connection->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        foreach (['replace', 'store', 'reveal'] as $method) {
            $vault->expects(self::never())->method($method);
        }
        $manager = new MailConfigurationManager($connection, self::createStub(AdministrativeAuthorization::class), $vault, self::createStub(AuditSink::class), self::createStub(MailProbe::class));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Enter the SMTP password');
        $manager->configureSecureSmtp(self::createStub(AdministrativeActor::class), $host, 587, 'starttls', $username, '', 'sender@example.test', 'Sender', '');
    }
}
