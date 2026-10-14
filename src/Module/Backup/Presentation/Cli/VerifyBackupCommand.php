<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Cli;

use App\Module\Backup\Application\BackupEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:backup:verify', description: 'Authenticate and verify a private backup without restoring it.')]
final class VerifyBackupCommand extends Command
{
    public function __construct(private readonly BackupEngine $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        if (!\is_string($path)) {
            return Command::INVALID;
        }
        $manifest = $this->backups->verify($path);
        $backupId = $manifest['backup_id'] ?? 'unknown';
        $output->writeln('<info>Backup verified: '.(\is_string($backupId) ? $backupId : 'unknown').'.</info>');

        return Command::SUCCESS;
    }
}
