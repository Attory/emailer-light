<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use App\Module\Secrets\Application\RecoveryKeyExporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'emailer:secrets:export-recovery-key', description: 'Export a separately stored passphrase-encrypted master-key recovery package.')]
final class ExportRecoveryKeyCommand extends Command
{
    public function __construct(private readonly RecoveryKeyExporter $exporter)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('verify-existing', null, InputOption::VALUE_NONE, 'Authenticate the existing package against this installation without replacing it.');
        $this->addOption('passphrase-stdin', null, InputOption::VALUE_NONE, 'Read the passphrase and confirmation from protected standard input for automated provisioning.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromStdin = true === $input->getOption('passphrase-stdin');
        if (!$fromStdin && !$input->isInteractive()) {
            $output->writeln('<error>Interactive input is required.</error>');

            return Command::FAILURE;
        }
        $question = (new Question('Recovery passphrase: '))->setHidden(true)->setHiddenFallback(false);
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return Command::FAILURE;
        }
        $passphrase = $fromStdin ? fgets(STDIN, 4098) : $helper->ask($input, $output, $question);
        if ($fromStdin && \is_string($passphrase)) {
            $passphrase = rtrim($passphrase, "\r\n");
        }
        if (!\is_string($passphrase)) {
            return Command::FAILURE;
        }
        $confirm = $fromStdin ? fgets(STDIN, 4098) : $helper->ask($input, $output, (new Question('Confirm passphrase: '))->setHidden(true)->setHiddenFallback(false));
        if ($fromStdin && \is_string($confirm)) {
            $confirm = rtrim($confirm, "\r\n");
        }
        if (!\is_string($confirm) || !hash_equals($passphrase, $confirm)) {
            $output->writeln('<error>Passphrases did not match.</error>');

            return Command::FAILURE;
        }
        try {
            if (true === $input->getOption('verify-existing')) {
                $this->exporter->verifyCurrent($passphrase);
            } else {
                $this->exporter->export($passphrase);
            }
            $output->writeln('<info>Recovery-key package exported. Store it separately from backups.</info>');

            return Command::SUCCESS;
        } finally {
            sodium_memzero($passphrase);
            sodium_memzero($confirm);
        }
    }
}
