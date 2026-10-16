<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Cli;

use App\Module\Health\Application\Doctor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:health:verify-runtime', description: 'Verify application structure while retaining all delivery and business readiness findings.')]
final class VerifyRuntimeCommand extends Command
{
    public function __construct(private readonly Doctor $doctor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('offline-maintenance', null, InputOption::VALUE_NONE, 'Verify a quiesced, idle application while its services are intentionally stopped.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->doctor->verifyRuntime(true === $input->getOption('offline-maintenance'));
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return true === $result['runtime_verified'] ? Command::SUCCESS : Command::FAILURE;
    }
}
