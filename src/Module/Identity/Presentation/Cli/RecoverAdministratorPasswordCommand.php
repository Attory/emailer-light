<?php

declare(strict_types=1);

namespace App\Module\Identity\Presentation\Cli;

use App\Module\Identity\Application\AdministratorPasswordManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'emailer:administrator:recover-password', description: 'Recover the sole administrator password in a protected interactive local terminal.')]
final class RecoverAdministratorPasswordCommand extends Command
{
    public function __construct(private readonly AdministratorPasswordManager $passwords)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!\in_array(PHP_SAPI, ['cli', 'phpdbg'], true) || !$input->isInteractive() || !\function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            $output->writeln('<error>Protected interactive terminal required.</error>');

            return self::FAILURE;
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }
        $question = static fn (string $prompt): Question => (new Question($prompt))->setHidden(true)->setHiddenFallback(false);
        $first = $helper->ask($input, $output, $question('New administrator password: '));
        $second = $helper->ask($input, $output, $question('Repeat new administrator password: '));
        try {
            if (!\is_string($first) || !\is_string($second) || !hash_equals($first, $second)) {
                throw new \InvalidArgumentException('Passwords did not match.');
            }
            $this->passwords->recover($first);
            $output->writeln('<info>Administrator password recovered. Existing sessions were revoked and an audit event was recorded.</info>');

            return self::SUCCESS;
        } catch (\Throwable) {
            $output->writeln('<error>Recovery was not accepted; inspect installation state and password policy.</error>');

            return self::FAILURE;
        } finally {
            if (\is_string($first)) {
                sodium_memzero($first);
            }
            if (\is_string($second)) {
                sodium_memzero($second);
            }
        }
    }
}
