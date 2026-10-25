<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use App\Module\Release\Application\NativeSchemaUpdate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:release:update-native', description: 'Installer-only verified Light update and interrupted-operation recovery.')]
final class UpdateNativeCommand extends Command
{
    public function __construct(private readonly NativeSchemaUpdate $updates)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('package', InputArgument::REQUIRED);
        $this->addArgument('source-package', InputArgument::REQUIRED);
        $this->addOption('recover', null, InputOption::VALUE_NONE);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->perform($input, $output);
        } catch (\Throwable $error) {
            if ('1' !== getenv('EMAILER_INSTALLER_DIAGNOSTICS')) {
                throw $error;
            }
            $output->writeln(json_encode(\App\Module\Release\Application\Contract\InstallerFailure::report($error), JSON_THROW_ON_ERROR));

            return Command::FAILURE;
        }
    }

    private function perform(InputInterface $input, OutputInterface $output): int
    {
        $package = $input->getArgument('package');
        $source = $input->getArgument('source-package');
        if (!\is_string($package) || !\is_string($source)) {
            return Command::INVALID;
        }
        $result = $this->updates->run($package, $source, true === $input->getOption('recover'));
        $output->writeln(json_encode($result, JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
