<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use App\Module\Secrets\Application\RecoveryKeyExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'emailer:secrets:recover-master-key', description: 'Recover a missing master key from the separately stored encrypted recovery package.')]
final class RecoverMasterKeyCommand extends Command
{
    public function __construct(private readonly RecoveryKeyExporter $recovery)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper || !$input->isInteractive()) {
            return Command::FAILURE;
        }
        $passphrase = $helper->ask($input, $output, (new Question('Recovery passphrase: '))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($passphrase)) {
            return Command::FAILURE;
        }
        try {
            $this->recovery->recover($passphrase);
            $output->writeln('<info>Missing master key recovered. Run backup verification and Doctor before resuming delivery.</info>');

            return Command::SUCCESS;
        } finally {
            sodium_memzero($passphrase);
        }
    }
}
