<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Cli;

use App\Module\Setup\Application\Contract\BackupValidator;
use App\Module\Setup\Application\Contract\ChallengeStore;
use App\Module\Setup\Application\Contract\CliSetupState;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\ProductionDataDetector;
use App\Module\Setup\Application\Contract\ProofFile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emailer:setup:reset',
    description: 'Reset setup lock through an interactive, backup-gated CLI operation.',
)]
final class SetupResetCommand extends Command
{
    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly ChallengeStore $challengeStore,
        private readonly ProofFile $proofFile,
        private readonly CliSetupState $cliSetupStateStore,
        private readonly BackupValidator $backupValidator,
        private readonly ProductionDataDetector $productionDataDetector,
        private readonly Clock $clock,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backup', null, InputOption::VALUE_REQUIRED, 'Path to a recent restrictive backup artifact.')
            ->addOption('destructive', null, InputOption::VALUE_NONE, 'Explicitly authorize reset when production data exists.')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->installationLock->exists()) {
            $io->note('Setup is already unlocked; no reset was performed.');

            return self::SUCCESS;
        }

        $backupPath = $input->getOption('backup');

        if (!\is_string($backupPath) || !$this->backupValidator->isValid($backupPath, $this->clock->now())) {
            $io->error('Reset requires a supported integrity-authenticated backup artifact. Phase 2 cannot validate one, so reset remains unavailable until backup tooling is implemented.');

            return self::FAILURE;
        }

        try {
            $hasProductionData = $this->productionDataDetector->hasProductionData();
        } catch (\Throwable) {
            $io->error('Production-data safety could not be determined; reset refused.');

            return self::FAILURE;
        }

        if ($hasProductionData && true !== $input->getOption('destructive')) {
            $io->error('Production data exists; reset requires the explicit --destructive flag.');

            return self::FAILURE;
        }

        if (!$input->isInteractive() || !$io->confirm('Permanently remove the installation lock and reopen setup?', false)) {
            $io->warning('Reset cancelled.');

            return self::FAILURE;
        }

        $this->challengeStore->delete();
        $this->proofFile->delete();
        $this->cliSetupStateStore->delete();
        $this->installationLock->remove();
        $io->success('Setup lock removed. Existing secrets were neither read nor exported.');

        return self::SUCCESS;
    }
}
