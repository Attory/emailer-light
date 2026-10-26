<?php

declare(strict_types=1);

namespace App\Tests\Module\Configuration;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\MailProbe;
use App\Module\Configuration\Application\Contract\MailTestFailure;
use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Configuration\Application\MailConnectionEvidence;
use App\Module\Configuration\Application\MailConnectionProof;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Secrets\Application\Contract\SecretVault;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MailCurrentFormTest extends TestCase
{
    private string $evidenceDirectory;

    protected function setUp(): void
    {
        $this->evidenceDirectory = sys_get_temp_dir().'/smtp-evidence-'.bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->evidenceDirectory)) {
            $paths = glob($this->evidenceDirectory.'/*');
            foreach (false === $paths ? [] : $paths as $path) {
                unlink($path);
            }
            rmdir($this->evidenceDirectory);
        }
    }

    /** @return array<string, mixed> */
    private function saved(): array
    {
        return ['smtp_host' => 'smtp.example.test', 'smtp_port' => 587, 'encryption_mode' => 'starttls', 'smtp_username' => 'saved-login', 'credential_secret_id' => 'synthetic-secret-id', 'installation_uuid' => '01990000-0000-7000-8000-000000000001', 'sender_address' => 'sender@example.test', 'sender_name' => 'Sender', 'reply_to_address' => null, 'one_click_dkim_confirmed' => 0, 'updated_at' => '2026-10-26 00:00:00.000001'];
    }

    private function actor(): AdministrativeActor
    {
        $actor = self::createStub(AdministrativeActor::class);
        $actor->method('id')->willReturn('01990000-0000-7000-8000-000000000002');

        return $actor;
    }

    private function manager(Connection $db, SecretVault $vault, MailProbe $probe): MailConfigurationManager
    {
        return new MailConfigurationManager($db, self::createStub(AdministrativeAuthorization::class), $vault, self::createStub(AuditSink::class), $probe, connectionEvidence: new MailConnectionEvidence('synthetic-evidence-only', $this->evidenceDirectory));
    }

    public function testUnsavedHostLoginAndPasswordAreTestedWithoutStoringOrMarkingSavedConfiguration(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        foreach (['reveal', 'store', 'replace'] as $method) {
            $vault->expects(self::never())->method($method);
        }
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::once())->method('check')->with('new.example.test', 465, 'tls', 'new-login', self::callback(static fn (string $value): bool => hash_equals('synthetic-new-password', $value)), '', null);
        $result = $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), ' NEW.example.test ', 465, 'tls', ' new-login ', 'synthetic-new-password');
        self::assertFalse($result['saved']);
        $session = json_encode($result['proof']->sessionValue(), JSON_THROW_ON_ERROR);
        foreach (['synthetic-new-password', 'new-login', 'new.example.test'] as $value) {
            self::assertStringNotContainsString($value, $session);
        }
        self::assertNotNull(MailConnectionProof::fromSession($result['proof']->sessionValue()));
    }

    /** @return iterable<string, array{string, string}> */
    public static function changedIdentity(): iterable
    {
        yield 'host changed' => ['new.example.test', 'saved-login'];
        yield 'username changed' => ['smtp.example.test', 'new-login'];
    }

    #[DataProvider('changedIdentity')]
    public function testBlankPasswordCannotBeReusedForDifferentIdentity(string $host, string $username): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        $vault->expects(self::never())->method('reveal');
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::never())->method('check');
        try {
            $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), $host, 587, 'starttls', $username, '');
            self::fail('A credential was reused for a different identity.');
        } catch (MailTestFailure $failure) {
            self::assertSame('incomplete', $failure->category);
            self::assertStringContainsString('Enter the SMTP password', $failure->getMessage());
        }
    }

    public function testSameHostAndUsernameCanReuseCredentialForAChangedSecurePortWithoutSaving(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        $vault->expects(self::once())->method('reveal')->with('synthetic-secret-id', 'smtp.credential', $this->saved()['installation_uuid'])->willReturn('synthetic-stored-password');
        $vault->expects(self::never())->method('store');
        $vault->expects(self::never())->method('replace');
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::once())->method('check')->with('smtp.example.test', 465, 'tls', 'saved-login', self::callback(static fn (string $value): bool => hash_equals('synthetic-stored-password', $value)), '', null);
        $result = $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), 'smtp.example.test', 465, 'tls', 'saved-login', '');
        self::assertFalse($result['saved']);
    }

    public function testExactlyMatchingSavedFieldsAreMarkedOnlyAfterSuccessfulAuthAndRecheck(): void
    {
        $saved = $this->saved();
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($saved);
        $db->method('transactional')->willReturnCallback(static fn (\Closure $operation): mixed => $operation());
        $db->expects(self::once())->method('update')->with('sender_identity', self::callback(static fn (array $values): bool => ['test_message_at'] === array_keys($values)), self::anything())->willReturn(1);
        $vault = self::createMock(SecretVault::class);
        $vault->expects(self::exactly(2))->method('reveal')->willReturn('synthetic-stored-password');
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::once())->method('check')->with('smtp.example.test', 587, 'starttls', 'saved-login', self::anything(), 'sender@example.test', null);
        $result = $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), 'smtp.example.test', 587, 'starttls', 'saved-login', '', 'sender@example.test', 'Sender');
        self::assertTrue($result['saved']);
    }

    public function testConcurrentSavedCredentialChangeCannotMarkAnotherConfigurationReady(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->method('transactional')->willReturnCallback(static fn (\Closure $operation): mixed => $operation());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        $vault->expects(self::exactly(2))->method('reveal')->willReturnOnConsecutiveCalls('synthetic-original-password', 'synthetic-changed-password');
        $result = $this->manager($db, $vault, self::createStub(MailProbe::class))->testCurrentConfiguration($this->actor(), 'smtp.example.test', 587, 'starttls', 'saved-login', '', 'sender@example.test', 'Sender');
        self::assertFalse($result['saved']);
    }

    public function testFailureLeavesStoredConfigurationAndCredentialUntouched(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        $vault->expects(self::never())->method('store');
        $vault->expects(self::never())->method('replace');
        $vault->method('reveal')->willReturn('synthetic-stored-password');
        $probe = self::createStub(MailProbe::class);
        $probe->method('check')->willThrowException(new MailTestFailure('authentication'));
        $this->expectException(MailTestFailure::class);
        $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-rejected-password');
    }

    public function testExpiredDifferentActorOrChangedConnectionCannotReuseTestEvidence(): void
    {
        $evidence = new MailConnectionEvidence('synthetic-evidence-only', $this->evidenceDirectory);
        $fingerprint = $evidence->fingerprint('actor', 'smtp.example.test', 587, 'starttls', 'login', 'synthetic-password');
        $proof = new MailConnectionProof('actor', $fingerprint, time() + 500, $evidence->begin('actor'));
        self::assertTrue($evidence->matches($proof, 'actor', 'smtp.example.test', 587, 'starttls', 'login', 'synthetic-password'));
        self::assertFalse($evidence->matches($proof, 'actor', 'smtp.example.test', 465, 'tls', 'login', 'synthetic-password'));
        self::assertFalse($evidence->matches($proof, 'actor', 'smtp.example.test', 587, 'starttls', 'login', 'different-password'));
        self::assertFalse($evidence->matches($proof, 'different-actor', 'smtp.example.test', 587, 'starttls', 'login', 'synthetic-password'));
        self::assertNull(MailConnectionProof::fromSession(['actor' => 'actor', 'fingerprint' => $fingerprint, 'expires' => time() - 1]));
    }

    /** @return iterable<string, array{int, bool, bool}> */
    public static function saveEvidenceCases(): iterable
    {
        yield 'matching current credentials' => [587, false, true];
        yield 'matching reused credential' => [587, true, true];
        yield 'changed secure port' => [465, false, false];
    }

    #[DataProvider('saveEvidenceCases')]
    public function testOnlyExplicitMatchingSaveAppliesConnectionEvidence(int $port, bool $reuse, bool $expected): void
    {
        $actor = $this->actor();
        $evidence = new MailConnectionEvidence('synthetic-evidence-only', $this->evidenceDirectory);
        $proof = new MailConnectionProof($actor->id(), $evidence->fingerprint($actor->id(), 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-password'), time() + 500, $evidence->begin($actor->id()));
        $db = self::createMock(Connection::class);
        $db->method('transactional')->willReturnCallback(static fn (\Closure $work): mixed => $work());
        $db->method('fetchOne')->willReturn($this->saved()['installation_uuid']);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::once())->method('update')->with('sender_identity', self::callback(static fn (array $values): bool => $expected === ('1970-01-01 00:00:00.000000' !== $values['test_message_at'])), ['singleton_key' => '1']);
        $vault = self::createMock(SecretVault::class);
        $vault->expects($reuse ? self::once() : self::never())->method('reveal')->willReturn('synthetic-password');
        $vault->expects($reuse ? self::never() : self::once())->method('replace');
        $vault->expects(self::never())->method('store');
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::never())->method('check');
        self::assertSame($expected, $this->manager($db, $vault, $probe)->configureSecureSmtp($actor, 'smtp.example.test', $port, 'starttls', 'saved-login', $reuse ? '' : 'synthetic-password', 'sender@example.test', 'Sender', '', testProof: $proof));
    }

    public function testEnteredReplacementPasswordWorksWhenStoredSecretIsUnreadable(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createMock(SecretVault::class);
        $vault->method('reveal')->willThrowException(new \RuntimeException('Synthetic unreadable saved secret.'));
        $vault->expects(self::never())->method('replace');
        $probe = self::createMock(MailProbe::class);
        $probe->expects(self::once())->method('check')->with('smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-replacement-password', '', null);
        $result = $this->manager($db, $vault, $probe)->testCurrentConfiguration($this->actor(), 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-replacement-password');
        self::assertFalse($result['saved']);
    }

    public function testInvalidEnteredReplyToCannotBeReportedAsMatchingSavedSettings(): void
    {
        $db = self::createMock(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $db->expects(self::never())->method('update');
        $vault = self::createStub(SecretVault::class);
        $vault->method('reveal')->willReturn('synthetic-stored-password');
        $result = $this->manager($db, $vault, self::createStub(MailProbe::class))->testCurrentConfiguration($this->actor(), 'smtp.example.test', 587, 'starttls', 'saved-login', '', 'sender@example.test', 'Sender', 'invalid reply address');
        self::assertFalse($result['saved']);
    }

    public function testFailedRetestInvalidatesAStaleCopyOfTheSuccessfulSessionProof(): void
    {
        $db = self::createStub(Connection::class);
        $db->method('fetchAssociative')->willReturn($this->saved());
        $vault = self::createStub(SecretVault::class);
        $vault->method('reveal')->willReturn('synthetic-password');
        $probe = self::createStub(MailProbe::class);
        $calls = 0;
        $probe->method('check')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls > 1) {
                throw new MailTestFailure('authentication');
            }
        });
        $manager = $this->manager($db, $vault, $probe);
        $actor = $this->actor();
        $first = $manager->testCurrentConfiguration($actor, 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-password');
        $staleSessionPayload = $first['proof']->sessionValue();
        try {
            $manager->testCurrentConfiguration($actor, 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-password');
            self::fail('The authentication failure was not exercised.');
        } catch (MailTestFailure $failure) {
            self::assertSame('authentication', $failure->category);
        }
        // Model the unlocked database session handler writing its old payload
        // after the failed retest: it cannot restore authoritative validity.
        $resurrected = MailConnectionProof::fromSession($staleSessionPayload);
        $evidence = new MailConnectionEvidence('synthetic-evidence-only', $this->evidenceDirectory);
        self::assertNotNull($resurrected);
        self::assertFalse($evidence->matches($resurrected, $actor->id(), 'smtp.example.test', 587, 'starttls', 'saved-login', 'synthetic-password'));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidEvidenceStates(): iterable
    {
        foreach (['missing', 'truncated', 'symlink', 'hardlink', 'public_mode'] as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('invalidEvidenceStates')]
    public function testUnsafeOrMissingAuthoritativeEvidenceCannotValidateAnOldSession(string $state): void
    {
        $evidence = new MailConnectionEvidence('synthetic-evidence-only', $this->evidenceDirectory);
        $actor = 'synthetic-actor';
        $proof = new MailConnectionProof($actor, $evidence->fingerprint($actor, 'smtp.example.test', 587, 'starttls', 'login', 'synthetic-password'), time() + 500, $evidence->begin($actor));
        $path = $this->evidenceDirectory.'/'.hash('sha256', $actor).'.attempt';
        if ('missing' === $state) {
            unlink($path);
        } elseif ('truncated' === $state) {
            file_put_contents($path, 'invalid');
        } elseif ('public_mode' === $state) {
            chmod($path, 0o644);
        } elseif ('hardlink' === $state) {
            self::assertTrue(link($path, $this->evidenceDirectory.'/second-link'));
        } elseif ('symlink' === $state) {
            rename($path, $this->evidenceDirectory.'/original');
            self::assertTrue(symlink($this->evidenceDirectory.'/original', $path));
        }
        self::assertFalse($evidence->matches($proof, $actor, 'smtp.example.test', 587, 'starttls', 'login', 'synthetic-password'));
        if ('missing' !== $state) {
            $this->expectException(\RuntimeException::class);
            $evidence->begin($actor);
        }
    }
}
