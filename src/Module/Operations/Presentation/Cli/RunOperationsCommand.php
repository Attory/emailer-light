<?php

declare(strict_types=1);

namespace App\Module\Operations\Presentation\Cli;

use App\Module\Operations\Application\OperationsRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:operations:run', description: 'Run due Doctor, retention, and encrypted external-backup operations under a non-overlap lock.')]
final class RunOperationsCommand extends Command
{
    public function __construct(private readonly OperationsRunner $runner)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->runner->runDue();
        $output->writeln(json_encode($results, JSON_THROW_ON_ERROR));

        return \count(array_filter($results, static fn (string $result): bool => str_starts_with($result, 'failed:'))) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
