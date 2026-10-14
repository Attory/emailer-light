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

#[AsCommand(name: 'emailer:secrets:export-recovery-key', description: 'Export a separately stored passphrase-encrypted master-key recovery package.')]
final class ExportRecoveryKeyCommand extends Command
{
    public function __construct(private readonly RecoveryKeyExporter $exporter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $output->writeln('<error>Interactive input is required.</error>');

            return Command::FAILURE;
        }
        $question = (new Question('Recovery passphrase: '))->setHidden(true)->setHiddenFallback(false);
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return Command::FAILURE;
        }
        $passphrase = $helper->ask($input, $output, $question);
        if (!\is_string($passphrase)) {
            return Command::FAILURE;
        }
        $confirm = $helper->ask($input, $output, (new Question('Confirm passphrase: '))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($confirm) || !hash_equals($passphrase, $confirm)) {
            $output->writeln('<error>Passphrases did not match.</error>');

            return Command::FAILURE;
        }
        try {
            $this->exporter->export($passphrase);
            $output->writeln('<info>Recovery-key package exported. Store it separately from backups.</info>');

            return Command::SUCCESS;
        } finally {
            sodium_memzero($passphrase);
            sodium_memzero($confirm);
        }
    }
}
