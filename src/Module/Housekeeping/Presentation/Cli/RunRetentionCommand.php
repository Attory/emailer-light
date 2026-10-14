<?php

declare(strict_types=1);

namespace App\Module\Housekeeping\Presentation\Cli;

use App\Module\Housekeeping\Application\Housekeeper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:retention:run', description: 'Preview or apply bounded idempotent MVP housekeeping.')]
final class RunRetentionCommand extends Command
{
    public function __construct(private readonly Housekeeper $housekeeper)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Apply the bounded batch; default is dry-run.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = true !== $input->getOption('apply');
        $counts = $this->housekeeper->run($dryRun);
        $output->writeln(($dryRun ? 'dry-run ' : 'applied ').json_encode($counts, JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
