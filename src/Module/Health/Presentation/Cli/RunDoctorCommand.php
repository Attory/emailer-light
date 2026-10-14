<?php

declare(strict_types=1);

namespace App\Module\Health\Presentation\Cli;

use App\Module\Health\Application\Doctor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:doctor:run', description: 'Run the redacted MVP readiness checks.')]
final class RunDoctorCommand extends Command
{
    public function __construct(private readonly Doctor $doctor)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Emit a redacted machine-readable report.');
        $this->addOption('maintenance-verification', null, InputOption::VALUE_NONE, 'Keep the known delivery maintenance state visible as a warning during a supervised update.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->doctor->run(true, true === $input->getOption('maintenance-verification'));
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln(\sprintf('Doctor status: %s (%d critical, %d warning)', $result['status'], $result['critical_count'], $result['warning_count']));
            foreach ($result['findings'] as $finding) {
                $output->writeln(\sprintf('[%s] %s: %s Remediation: %s', strtoupper($finding['severity']), $finding['code'], $finding['summary'], $finding['remediation']));
            }
        }

        return 0 < $result['critical_count'] ? 2 : (0 < $result['warning_count'] ? 1 : Command::SUCCESS);
    }
}
