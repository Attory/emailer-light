<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup;

use App\Module\Setup\Application\ActiveChallengeExists;
use App\Module\Setup\Application\ChallengeCode;
use App\Module\Setup\Application\ChallengeIssuer;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\RandomBytes;
use App\Module\Setup\Application\ProofVerificationResult;
use App\Module\Setup\Application\ProofVerifier;
use App\Module\Setup\Infrastructure\FilesystemChallengeStore;
use App\Module\Setup\Infrastructure\FilesystemProofFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class ProofVerifierTest extends TestCase
{
    private string $directory;
    private string $stateFile;
    private string $proofFile;
    private MutableClock $clock;
    private FilesystemChallengeStore $store;
    private ChallengeIssuer $issuer;
    private ProofVerifier $verifier;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-setup-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o700, true));
        $this->stateFile = $this->directory.'/private/setup/challenge.json';
        $this->proofFile = $this->directory.'/.emailer-install-proof';
        $this->clock = new MutableClock(1_800_000_000);
        $this->store = new FilesystemChallengeStore($this->stateFile);
        $lockFactory = new LockFactory(new FlockStore($this->directory.'/locks'));
        $random = new SequenceRandomBytes();
        $this->issuer = new ChallengeIssuer($this->store, $this->clock, $random, $lockFactory);
        $this->verifier = new ProofVerifier(
            $this->store,
            new FilesystemProofFile($this->proofFile),
            $this->clock,
            $lockFactory,
        );
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testCorrectProofIsSingleUseAndDeletesPrivateState(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');
        $this->writeProof($challenge->displayCode."\r\n");

        self::assertSame(ProofVerificationResult::Success, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
        self::assertFileDoesNotExist($this->proofFile);
        self::assertFileDoesNotExist($this->stateFile);
        self::assertSame(ProofVerificationResult::InvalidAuthority, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
    }

    public function testMissingWrongAndDifferentIpProofsAreGenericFailures(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');
        self::assertSame(ProofVerificationResult::RetryableFailure, $this->verifier->verify($challenge->identifier, '192.0.2.10'));

        $this->writeProof(ChallengeCode::display(str_repeat('0', 32)));
        self::assertSame(ProofVerificationResult::RetryableFailure, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
        self::assertSame(ProofVerificationResult::InvalidAuthority, $this->verifier->verify($challenge->identifier, '192.0.2.11'));
        self::assertSame(3, $this->store->load()?->failedAttempts);
    }

    public function testExpiredChallengeIsRotatedBeforeReadingProof(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');
        $this->clock->timestamp += 901;
        $this->writeProof($challenge->displayCode);

        self::assertSame(ProofVerificationResult::Expired, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
        self::assertFileDoesNotExist($this->stateFile);
        self::assertFileExists($this->proofFile);
    }

    public function testTenthFailedAttemptRotatesChallengeState(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');

        for ($attempt = 1; $attempt < 10; ++$attempt) {
            self::assertSame(ProofVerificationResult::RetryableFailure, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
        }

        self::assertSame(ProofVerificationResult::AttemptsExceeded, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
        self::assertFileDoesNotExist($this->stateFile);
    }

    /** @param callable(string): void $makeUnsafeProof */
    #[DataProvider('unsafeProofProvider')]
    public function testUnsafeProofFilesystemObjectsAreRejected(callable $makeUnsafeProof): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');
        $makeUnsafeProof($this->proofFile);

        self::assertSame(ProofVerificationResult::RetryableFailure, $this->verifier->verify($challenge->identifier, '192.0.2.10'));
    }

    /** @return iterable<string, array{callable(string): void}> */
    public static function unsafeProofProvider(): iterable
    {
        yield 'symlink' => [static function (string $path): void {
            $target = $path.'.target';
            file_put_contents($target, str_repeat('0', 32));
            symlink($target, $path);
        }];
        yield 'oversized' => [static function (string $path): void {
            file_put_contents($path, str_repeat('0', 257));
            chmod($path, 0o600);
        }];
        yield 'directory' => [static function (string $path): void {
            mkdir($path, 0o700);
        }];
        yield 'group writable' => [static function (string $path): void {
            file_put_contents($path, str_repeat('0', 32));
            chmod($path, 0o620);
        }];
        yield 'world writable' => [static function (string $path): void {
            file_put_contents($path, str_repeat('0', 32));
            chmod($path, 0o602);
        }];
    }

    public function testStateContainsOnlyHashMetadataAndUsesRestrictiveMode(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');
        $contents = (string) file_get_contents($this->stateFile);

        self::assertStringNotContainsString(str_replace('-', '', $challenge->displayCode), $contents);
        self::assertSame(0o600, fileperms($this->stateFile) & 0o777);
        self::assertSame(0o700, fileperms(\dirname($this->stateFile)) & 0o777);
    }

    public function testActiveChallengeCannotBeReplaced(): void
    {
        $challenge = $this->issuer->issue('192.0.2.10');

        try {
            $this->issuer->issue('192.0.2.11');
            self::fail('An active challenge was replaced.');
        } catch (ActiveChallengeExists $exception) {
            self::assertSame($challenge->identifier, $exception->identifier);
        }

        self::assertSame($challenge->identifier, $this->store->load()?->identifier);
    }

    public function testExpiredOrExhaustedChallengeCanBeReplaced(): void
    {
        $expired = $this->issuer->issue('192.0.2.10');
        $this->clock->timestamp = $expired->expiresAt;
        $replacement = $this->issuer->issue('192.0.2.11');
        self::assertNotSame($expired->identifier, $replacement->identifier);

        $state = $this->store->load();
        self::assertNotNull($state);
        $this->store->save(new \App\Module\Setup\Domain\ChallengeState(
            $state->identifier,
            $state->codeHash,
            $state->createdAt,
            $state->expiresAt,
            10,
            $state->requestIpHash,
        ));

        $afterExhaustion = $this->issuer->issue('192.0.2.12');
        self::assertNotSame($replacement->identifier, $afterExhaustion->identifier);
    }

    private function writeProof(string $contents): void
    {
        self::assertNotFalse(file_put_contents($this->proofFile, $contents));
        self::assertTrue(chmod($this->proofFile, 0o600));
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }

        $entries = scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            self::removeDirectory($directory.'/'.$entry);
        }

        @rmdir($directory);
    }
}

final class MutableClock implements Clock
{
    public function __construct(public int $timestamp)
    {
    }

    public function now(): int
    {
        return $this->timestamp;
    }
}

final class SequenceRandomBytes implements RandomBytes
{
    private int $sequence = 0;

    public function generate(int $length): string
    {
        return str_repeat(\chr(++$this->sequence), $length);
    }
}
