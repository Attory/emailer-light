<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Release\Application\ReleaseManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:release:inspect', description: 'Verify a signed release package without changing installation state.')]
final class InspectReleaseCommand extends Command
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
        $manifest = $this->releases->inspect($package);
        $version = $manifest['version'] ?? null;
        $commit = $manifest['source_commit'] ?? null;
        if (!\is_string($version) || !\is_string($commit)) {
            return Command::FAILURE;
        }
        $output->writeln(\sprintf('<info>Verified release %s from source %s.</info>', $version, $commit));

        return Command::SUCCESS;
    }
}
