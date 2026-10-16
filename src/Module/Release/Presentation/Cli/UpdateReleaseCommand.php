<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Release\Application\ReleaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'emailer:release:update', description: 'Run the explicit verified backup, migration, atomic switch, and health-checked update workflow.')]
final class UpdateReleaseCommand extends Command
{
    public function __construct(private readonly ReleaseManager $releases)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED);
        $this->addOption('source-package', null, InputOption::VALUE_REQUIRED, 'Signed package for the exact installed predecessor release.');
        $this->addOption('approved-target', null, InputOption::VALUE_REQUIRED, 'Exact separately approved target version for a non-interactive supervised update.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->perform($input, $output);
        } catch (\Throwable $error) {
            if ('1' !== getenv('EMAILER_INSTALLER_DIAGNOSTICS')) {
                throw $error;
            }
            $output->writeln(json_encode(\App\Module\Release\Application\Contract\InstallerFailure::report($error), JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }
    }

    private function perform(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        if (!\is_string($package)) {
            return Command::INVALID;
        }
        $sourcePackage = $input->getOption('source-package');
        if (!\is_string($sourcePackage) || '' === trim($sourcePackage)) {
            $output->writeln('<error>Update requires the signed package for the installed predecessor.</error>');

            return Command::FAILURE;
        }
        $approvedTarget = $input->getOption('approved-target');
        if ($input->isInteractive()) {
            $helper = $this->getHelper('question');
            if (!$helper instanceof QuestionHelper || true !== $helper->ask($input, $output, new ConfirmationQuestion('Apply this verified update with maintenance and backup? [y/N] ', false))) {
                return Command::FAILURE;
            }
        } elseif (!\is_string($approvedTarget) || 1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/D', $approvedTarget)) {
            $output->writeln('<error>Non-interactive update requires an exact --approved-target.</error>');

            return Command::FAILURE;
        }
        if (\is_string($approvedTarget)) {
            $verifiedTarget = $this->releases->inspect($package)['version'] ?? null;
            if (!\is_string($verifiedTarget) || !hash_equals($approvedTarget, $verifiedTarget)) {
                $output->writeln('<error>The verified package target differs from --approved-target.</error>');

                return Command::FAILURE;
            }
        }
        $this->releases->update($package, $sourcePackage);
        $output->writeln('<info>Update completed and passed maintenance Doctor. Delivery remains quiesced; restart supervised worker/scheduler services, then run emailer:delivery:resume.</info>');

        return Command::SUCCESS;
    }
}
