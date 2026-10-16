<?php

declare(strict_types=1);

namespace App\Module\Backup\Presentation\Cli;

use App\Module\Backup\Application\BackupEngine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:backup:create', description: 'Create and verify an encrypted private backup.')]
final class CreateBackupCommand extends Command
{
    public function __construct(private readonly BackupEngine $backups)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Return the verified backup receipt for protected automation.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $backup = $this->backups->create();
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($backup, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }
        $output->writeln(\sprintf('<info>Verified backup %s created (%d bytes).</info>', $backup['id'], $backup['bytes']));

        return Command::SUCCESS;
    }
}
