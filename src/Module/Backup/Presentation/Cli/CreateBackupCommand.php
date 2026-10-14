<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Cli;

use App\Module\Backup\Application\BackupEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:backup:create', description: 'Create and verify an encrypted private backup.')]
final class CreateBackupCommand extends Command
{
    public function __construct(private readonly BackupEngine $backups)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backup = $this->backups->create();
        $output->writeln(\sprintf('<info>Verified backup %s created (%d bytes).</info>', $backup['id'], $backup['bytes']));

        return Command::SUCCESS;
    }
}
