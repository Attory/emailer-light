<?php

declare(strict_types=1);

namespace App\Module\Secrets\Presentation\Cli;

use App\Module\Identity\Application\Contract\AdministrativeUserLoader;
use App\Module\Secrets\Application\MasterKeyRotator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'emailer:secrets:rotate-master-key', description: 'Authenticate the administrator and transactionally rotate the external master key.')]
final class RotateMasterKeyCommand extends Command
{
    public function __construct(
        private readonly AdministrativeUserLoader $users,
        private readonly MasterKeyRotator $rotator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->isInteractive()) {
            $output->writeln('<error>Interactive Administrator authentication is required.</error>');

            return self::FAILURE;
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }

        $login = $helper->ask($input, $output, new Question('Administrator login: '));
        $password = $helper->ask($input, $output, (new Question('Administrator password: '))->setHidden(true)->setHiddenFallback(false));
        $recoveryPassphrase = $helper->ask($input, $output, (new Question('Current master-key recovery-package passphrase: '))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($login) || !\is_string($password) || !\is_string($recoveryPassphrase)) {
            return self::FAILURE;
        }

        try {
            $user = $this->users->loadForAuthentication($login);
            if (!$user->active() || !password_verify($password, $user->getPassword())) {
                $output->writeln('<error>Administrator authentication failed.</error>');

                return self::FAILURE;
            }
            $confirmed = $helper->ask($input, $output, new ConfirmationQuestion(
                'Create a verified backup and rotate every encrypted secret now? Delivery must be stopped [y/N] ',
                false,
            ));
            if (true !== $confirmed) {
                $output->writeln('<comment>Master-key rotation cancelled without mutation.</comment>');

                return self::FAILURE;
            }

            $result = $this->rotator->rotate($user->id(), $recoveryPassphrase);
            $output->writeln(\sprintf(
                '<info>Master key rotated for %d encrypted records after verified backup %s.</info>',
                $result['rotated_secret_count'],
                $result['backup_id'],
            ));
            $output->writeln('<comment>Before resuming delivery, export and independently store a new recovery-key package, create a new verified backup, and run Doctor.</comment>');

            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Master-key rotation failed safely. Keep delivery stopped and follow the recovery runbook.</error>');

            return self::FAILURE;
        } finally {
            sodium_memzero($password);
            sodium_memzero($recoveryPassphrase);
        }
    }
}
