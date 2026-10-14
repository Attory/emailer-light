<?php

declare(strict_types=1);

namespace App\Module\Delivery\Presentation\Cli;

use App\Module\Delivery\Application\DeliveryQuiescer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:delivery:quiesce', description: 'Stop new delivery claims and report the in-flight count.')]
final class DeliveryQuiesceCommand extends Command
{
    public function __construct(private readonly DeliveryQuiescer $quiescer)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('accepting_work=0 active_claims='.$this->quiescer->quiesce());

        return Command::SUCCESS;
    }
}
