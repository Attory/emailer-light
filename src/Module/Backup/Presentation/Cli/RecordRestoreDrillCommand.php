<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Cli;

use App\Module\Backup\Application\BackupEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'emailer:backup:record-restore-drill', description: 'Authenticate and record an isolated restore receipt without restoring the live database.')]
final class RecordRestoreDrillCommand extends Command
{
    public function __construct(private readonly BackupEngine $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('receipt', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper || !$input->isInteractive() || true !== $helper->ask($input, $output, new ConfirmationQuestion('Record authenticated isolated restore evidence for the retained backup [y/N] ', false))) {
            return Command::FAILURE;
        }
        $path = $input->getArgument('receipt');
        if (!\is_string($path)) {
            return Command::INVALID;
        }
        $this->backups->recordRestoreDrill($path);
        $output->writeln('<info>Restore evidence recorded. Run full Doctor to refresh findings.</info>');

        return Command::SUCCESS;
    }
}
