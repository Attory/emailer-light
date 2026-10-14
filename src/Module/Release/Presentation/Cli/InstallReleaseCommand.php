<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Release\Application\ReleaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:release:install', description: 'Install a verified package into a new immutable release directory without switching it.')]
final class InstallReleaseCommand extends Command
{
    public function __construct(private readonly ReleaseManager $releases)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        if (!\is_string($package)) {
            return Command::INVALID;
        }
        $target = $this->releases->install($package);
        $output->writeln('<info>Verified release installed at '.$target.'; current was not switched.</info>');

        return Command::SUCCESS;
    }
}
