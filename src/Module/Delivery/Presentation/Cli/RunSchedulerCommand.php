<?php

declare(strict_types=1);

namespace App\Module\Delivery\Presentation\Cli;

use App\Module\Delivery\Application\DeliveryScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:scheduler:run', description: 'Queue due campaigns and reconcile due delivery ledger work.')]
final class RunSchedulerCommand extends Command
{
    public function __construct(private readonly DeliveryScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostname = gethostname();
        $id = substr((false === $hostname ? 'host' : $hostname).'-'.getmypid(), 0, 100);
        $result = $this->scheduler->run($id);
        $output->writeln(\sprintf('scheduled=%d reconciled=%d', $result['scheduled'], $result['reconciled']));

        return Command::SUCCESS;
    }
}
