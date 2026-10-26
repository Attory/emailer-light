<?php

declare(strict_types=1);

namespace App\Module\Delivery\Presentation\Cli;

use App\Module\Delivery\Application\Contract\SendingNotReady;
use App\Module\Delivery\Application\DeliveryScheduler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:scheduler:run', description: 'Queue due campaigns and reconcile due delivery ledger work.')]
final class RunSchedulerCommand extends Command
{
    public function __construct(private readonly DeliveryScheduler $scheduler)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('wait-for-configuration', null, InputOption::VALUE_NONE, 'Report a closed sending gate as waiting; never queue or send through that gate.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hostname = gethostname();
        $id = substr((false === $hostname ? 'host' : $hostname).'-'.getmypid(), 0, 100);
        try {
            $result = $this->scheduler->run($id);
        } catch (SendingNotReady|\App\Shared\UpdateSendingBlocked $blocked) {
            if (true !== $input->getOption('wait-for-configuration')) {
                throw $blocked;
            }
            $output->writeln('scheduled=0 reconciled=0 status=waiting_for_readiness');

            return Command::SUCCESS;
        }
        $output->writeln(\sprintf('scheduled=%d reconciled=%d', $result['scheduled'], $result['reconciled']));

        return Command::SUCCESS;
    }
}
