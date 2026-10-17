<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup;

use App\Module\Setup\Application\Contract\BackupValidator;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\ProductionDataDetector;
use App\Module\Setup\Domain\ChallengeState;
use App\Module\Setup\Infrastructure\CliSetupStateStore;
use App\Module\Setup\Infrastructure\FilesystemChallengeStore;
use App\Module\Setup\Infrastructure\FilesystemInstallationLock;
use App\Module\Setup\Infrastructure\FilesystemProofFile;
use App\Module\Setup\Infrastructure\RecentBackupValidator;
use App\Module\Setup\Infrastructure\SystemPreflight;
use App\Module\Setup\Presentation\Cli\SetupInstallCommand;
use App\Module\Setup\Presentation\Cli\SetupResetCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SetupCommandsTest extends TestCase
{
    private string $directory;
    private string $privateDirectory;
    private string $publicDirectory;
    private FilesystemInstallationLock $installationLock;
    private FilesystemChallengeStore $challengeStore;
    private FilesystemProofFile $proofFile;
    private CliSetupStateStore $cliStateStore;
    private CommandClock $clock;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-command-'.bin2hex(random_bytes(8));
        $this->privateDirectory = $this->directory.'/var/private';
        $this->publicDirectory = $this->directory.'/public';
        self::assertTrue(mkdir($this->publicDirectory, 0o755, true));
        self::assertNotFalse(file_put_contents($this->publicDirectory.'/index.php', '<?php'));
        $this->installationLock = new FilesystemInstallationLock($this->privateDirectory.'/install.lock');
        $this->challengeStore = new FilesystemChallengeStore($this->privateDirectory.'/setup/challenge.json');
        $this->proofFile = new FilesystemProofFile($this->directory.'/.emailer-install-proof');
        $this->cliStateStore = new CliSetupStateStore($this->privateDirectory.'/setup/cli-state.json');
        $this->clock = new CommandClock(1_800_000_000);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testCliInstallRecordsOnlyCredentialFreeOwnershipState(): void
    {
        $preflight = new SystemPreflight($this->directory, $this->privateDirectory, []);
        $command = new SetupInstallCommand(
            $this->installationLock,
            $preflight,
            $this->cliStateStore,
            $this->clock,
            $this->publicDirectory,
        );
        foreach ([2, 15, 1] as $signal) {
            self::assertSame(128 + $signal, $command->handleSignal($signal));
            if (\defined('SIGINT')) {
                self::assertContains($signal, $command->getSubscribedSignals());
            } else {
                self::assertSame([], $command->getSubscribedSignals());
            }
        }
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        $stateFile = $this->privateDirectory.'/setup/cli-state.json';
        self::assertFileExists($stateFile);
        self::assertSame(0o600, fileperms($stateFile) & 0o777);
        self::assertStringContainsString('ownership_verified', (string) file_get_contents($stateFile));
        self::assertStringNotContainsString('password', (string) file_get_contents($stateFile));
    }

    public function testResetRequiresRecentBackupAndDestructiveFlagForData(): void
    {
        $this->installationLock->create(['completed_at' => $this->clock->now()]);
        $command = $this->resetCommand(new FixedProductionDataDetector(true));
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([], ['interactive' => false]));
        self::assertTrue($this->installationLock->exists());

        $command = $this->resetCommand(new FixedProductionDataDetector(true), new AcceptingBackupValidator());
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['--backup' => '/synthetic/accepted'], ['interactive' => false]));
        self::assertStringContainsString('--destructive', $tester->getDisplay());
        self::assertTrue($this->installationLock->exists());
    }

    public function testConfirmedDestructiveResetRemovesLockAndTransientSetupState(): void
    {
        $this->installationLock->create(['completed_at' => $this->clock->now()]);
        $this->challengeStore->save(new ChallengeState(
            'challenge',
            hash('sha256', 'code'),
            $this->clock->now(),
            $this->clock->now() + 900,
            0,
            hash('sha256', 'ip'),
        ));
        self::assertNotFalse(file_put_contents($this->directory.'/.emailer-install-proof', str_repeat('0', 32)));
        self::assertTrue(chmod($this->directory.'/.emailer-install-proof', 0o600));
        $this->cliStateStore->recordOwnershipVerified($this->clock->now());
        $tester = new CommandTester($this->resetCommand(
            new FixedProductionDataDetector(true),
            new AcceptingBackupValidator(),
        ));
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute([
            '--backup' => '/synthetic/accepted',
            '--destructive' => true,
        ]));
        self::assertFalse($this->installationLock->exists());
        self::assertFileDoesNotExist($this->privateDirectory.'/setup/challenge.json');
        self::assertFileDoesNotExist($this->privateDirectory.'/setup/cli-state.json');
        self::assertFileDoesNotExist($this->directory.'/.emailer-install-proof');
        self::assertStringNotContainsString('DATABASE_URL', $tester->getDisplay());
        self::assertStringNotContainsString('password=', strtolower($tester->getDisplay()));
    }

    private function resetCommand(
        ProductionDataDetector $detector,
        ?BackupValidator $backupValidator = null,
    ): SetupResetCommand {
        return new SetupResetCommand(
            $this->installationLock,
            $this->challengeStore,
            $this->proofFile,
            $this->cliStateStore,
            $backupValidator ?? new RecentBackupValidator($this->publicDirectory),
            $detector,
            $this->clock,
        );
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

final readonly class FixedProductionDataDetector implements ProductionDataDetector
{
    public function __construct(private bool $hasData)
    {
    }

    public function hasProductionData(): bool
    {
        return $this->hasData;
    }
}

final class AcceptingBackupValidator implements BackupValidator
{
    public function isValid(string $backupPath, int $now): bool
    {
        return true;
    }
}

final readonly class CommandClock implements Clock
{
    public function __construct(private int $timestamp)
    {
    }

    public function now(): int
    {
        return $this->timestamp;
    }
}
