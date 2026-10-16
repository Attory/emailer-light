<?php

declare(strict_types=1);

namespace App\Module\Setup\Presentation\Cli;

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Identity\Application\Contract\AdministratorSetup;
use App\Module\Setup\Application\Contract\CliSetupState;
use App\Module\Setup\Application\Contract\Clock;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\InstallationLock;
use App\Module\Setup\Application\Contract\Preflight;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'emailer:setup:install',
    description: 'Run the credential-free preflight or the HTTPS-independent interactive installer.',
)]
final class SetupInstallCommand extends Command
{
    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly Preflight $preflight,
        private readonly CliSetupState $cliSetupStateStore,
        private readonly Clock $clock,
        private readonly string $publicDirectory,
        private readonly string $canonicalUri = '',
        private readonly string $backupDestinationDirectory = '',
        private readonly ?\App\Module\Operations\Application\Contract\OperationsPolicyWriter $operationsPolicy = null,
        private readonly ?DatabaseSetup $databaseSetup = null,
        private readonly ?SetupState $setupState = null,
        private readonly ?ConfigurationSetup $configurationSetup = null,
        private readonly ?AdministratorSetup $administratorSetup = null,
        private readonly ?InstallationFinalizer $finalizer = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('guided', null, InputOption::VALUE_NONE, 'Use provisioned hosting settings and defer SMTP; ask only for the first administrator account.');
        $this->addOption('complete', null, InputOption::VALUE_NONE, 'Continue through the interactive full installation after preflight.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->installationLock->exists()) {
            $io->error('Setup is permanently locked for this installation.');

            return self::FAILURE;
        }

        $checks = $this->preflight->run($this->publicDirectory);
        $rows = [];
        $passed = true;

        foreach ($checks as $check) {
            $rows[] = [$check->name, $check->passed ? 'PASS' : 'FAIL', $check->message];
            $passed = $passed && $check->passed;
        }

        $io->table(['Check', 'Result', 'Guidance'], $rows);

        if (!$passed) {
            $io->error('Correct the failed preflight checks before continuing setup.');

            return self::FAILURE;
        }

        $this->cliSetupStateStore->recordOwnershipVerified($this->clock->now());
        if (true !== $input->getOption('complete') && true !== $input->getOption('guided')) {
            $io->success('CLI ownership and preflight are verified. Re-run with --complete in a protected interactive terminal to finish installation.');

            return self::SUCCESS;
        }
        if (!$input->isInteractive()) {
            $io->error('Full installation requires protected interactive input; secrets are never accepted as command-line options.');

            return self::FAILURE;
        }
        if (null === $this->databaseSetup || null === $this->setupState || null === $this->configurationSetup || null === $this->administratorSetup || null === $this->finalizer) {
            $io->error('Full installer services are unavailable.');

            return self::FAILURE;
        }

        try {
            return $this->completeInstallation($input, $output, $io);
        } catch (\InvalidArgumentException) {
            $io->error('Installer input was rejected. Review the documented format and retry; submitted secrets were not retained or printed.');

            return self::FAILURE;
        } catch (\Doctrine\DBAL\Exception) {
            $io->error('Database setup failed. Verify the protected DSN, server reachability, account grants, and migration status before retrying.');

            return self::FAILURE;
        } catch (\RuntimeException) {
            $io->error('Installation state validation failed. Verify key/private-path ownership, mail configuration, setup progress, and Doctor results before retrying.');

            return self::FAILURE;
        } catch (\Throwable) {
            $io->error('Installation failed in an unexpected safe category. Preserve the unlocked state and obtain an operator review before retrying.');

            return self::FAILURE;
        }
    }

    private function completeInstallation(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $databaseSetup = $this->databaseSetup;
        $setupState = $this->setupState;
        $configurationSetup = $this->configurationSetup;
        $administratorSetup = $this->administratorSetup;
        $finalizer = $this->finalizer;
        if (null === $databaseSetup || null === $setupState || null === $configurationSetup || null === $administratorSetup || null === $finalizer) {
            throw new \LogicException('Full installer services are unavailable.');
        }
        $helper = $this->getHelper('question');
        if (!$helper instanceof QuestionHelper) {
            return self::FAILURE;
        }
        $summaryRows = [];

        $guided = true === $input->getOption('guided');
        if ($guided) {
            $io->section('Preparing your application');
            $databaseSetup->configureRuntime();
            $summaryRows[] = ['Database', 'Dedicated protected hosting configuration verified'];
        } else {
            $io->section('Database and installation key');
            $host = $this->ask($helper, $input, $output, 'MariaDB host: ');
            $port = $this->askInt($helper, $input, $output, 'MariaDB port [3306]: ', 3306);
            $database = $this->ask($helper, $input, $output, 'MariaDB database: ');
            $username = $this->ask($helper, $input, $output, 'MariaDB runtime username: ');
            $databasePassword = $this->askSecret($helper, $input, $output, 'MariaDB password: ');
            try {
                $databaseSetup->configure(new DatabaseInput($host, $port, $database, $username, $databasePassword));
            } finally {
                sodium_memzero($databasePassword);
            }
            $summaryRows[] = ['Database', $host.':'.$port.'/'.$database.' as '.$username];
        }
        $installationId = $setupState->initializeKey();
        $summaryRows[] = ['Installation key', 'External service-owned key initialized and validated'];

        $progress = $setupState->progress();
        if (!$progress->organisationReady) {
            $io->section('Organisation');
            if ($guided) {
                $organisation = 'Emailer';
                $baseUrl = $this->canonicalUri;
                $locale = 'en_GB';
                $timeZone = 'UTC';
            } else {
                $organisation = $this->ask($helper, $input, $output, 'Organisation display name: ');
                $baseUrl = $this->ask($helper, $input, $output, 'Canonical HTTPS base URL: ');
                $locale = $this->ask($helper, $input, $output, 'Locale [en_AU]: ', 'en_AU');
                $timeZone = $this->ask($helper, $input, $output, 'Time zone [Australia/Sydney]: ', 'Australia/Sydney');
            }
            $configurationSetup->saveOrganisation($installationId, new OrganisationInput($organisation, $baseUrl, $locale, $timeZone));
            $setupState->mark('organisation');
            $summaryRows[] = ['Organisation', $organisation.' | '.$baseUrl.' | '.$locale.' | '.$timeZone];
        } else {
            $summaryRows[] = ['Organisation', 'Previously completed; verify in the protected administration UI'];
        }

        $progress = $setupState->progress();
        if (!$progress->administratorReady) {
            $io->section('Initial Administrator');
            $login = $this->ask($helper, $input, $output, 'Administrator email: ');
            $administratorPassword = $this->askSecret($helper, $input, $output, 'Administrator password (minimum 15 characters): ');
            $confirmation = $this->askSecret($helper, $input, $output, 'Confirm Administrator password: ');
            try {
                if (!hash_equals($administratorPassword, $confirmation)) {
                    throw new \InvalidArgumentException('Administrator passwords did not match.');
                }
                $administratorId = $administratorSetup->create($login, $administratorPassword);
            } finally {
                sodium_memzero($administratorPassword);
                sodium_memzero($confirmation);
            }
            $setupState->mark('administrator');
            $summaryRows[] = ['Initial Administrator', $login];
        } else {
            $summaryRows[] = ['Initial Administrator', 'Previously completed; verify identity in the protected administration UI'];
        }

        $progress = $setupState->progress();
        if (!$progress->mailReady) {
            if ($guided) {
                $io->note('Email delivery is not configured. You can sign in and prepare templates and campaigns; add SMTP in Mail settings when ready.');
                $summaryRows[] = ['Email delivery', 'Not configured; sending remains unavailable'];
            } else {
                $io->section('Isolated setup mail probe');
                $mailHost = $this->ask($helper, $input, $output, 'Local Mailpit host [mailpit]: ', 'mailpit');
                $mailPort = $this->askInt($helper, $input, $output, 'Local Mailpit SMTP port [1025]: ', 1025);
                $senderAddress = $this->ask($helper, $input, $output, 'Synthetic sender address: ');
                $senderName = $this->ask($helper, $input, $output, 'Synthetic sender name [Emailer Light Test]: ', 'Emailer Light Test');
                $configurationSetup->testAndSaveMail($installationId, new MailInput(
                    $mailHost,
                    $mailPort,
                    'none',
                    '',
                    '',
                    $senderAddress,
                    $senderName,
                    '',
                ));
                $summaryRows[] = ['Setup mail', $mailHost.':'.$mailPort.' | '.$senderAddress.' | '.$senderName.' | no TLS/auth'];
            }
            $setupState->mark('mail');
        } else {
            $summaryRows[] = ['Setup mail', 'Previously completed; production SMTP remains prohibited until authenticated reconfiguration'];
        }

        $progress = $setupState->progress();
        if (!$progress->policyReady || !$progress->capabilityReady) {
            $workerMode = $guided ? 'supervised' : $this->ask($helper, $input, $output, 'Worker mode, supervised or cron [supervised]: ', 'supervised');
            $configurationSetup->savePolicies(new PolicyInput(
                60,
                1,
                5,
                10_000,
                10_485_760,
                100_000,
                30,
                365,
                'You are receiving this message from the configured organisation. An unsubscribe link is included below.',
                $workerMode,
            ));
            if ($guided && '' !== $this->backupDestinationDirectory && null !== $this->operationsPolicy) {
                $this->operationsPolicy->updateBackup(['backup_automation_enabled' => true, 'backup_interval_hours' => 24, 'backup_retention_days' => 30, 'restore_drill_interval_days' => 30, 'backup_destination_type' => 'filesystem']);
                $summaryRows[] = ['Backups', 'Daily encrypted local backup; 30-day retention'];
            }
            $setupState->mark('policy');
            $setupState->mark('capability');
            $summaryRows[] = ['Worker policy', $workerMode];
        } else {
            $summaryRows[] = ['Worker policy', 'Previously completed; verify supervised/cron ownership in the protected administration UI'];
        }

        $io->section('Non-secret installation summary');
        $io->table(['Area', 'Recorded value or state'], $summaryRows);
        $io->note($guided ? 'Your account and security settings are ready. Email delivery stays unavailable until you configure it in Mail settings. Secret values are never displayed.' : 'Confirm the canonical origin, identities, isolated setup mail, worker ownership, and external key boundary. Secret values are intentionally omitted.');

        if (!$guided && true !== $helper->ask($input, $output, new ConfirmationQuestion(
            'Permanently lock setup and complete this installation? [y/N] ',
            false,
        ))) {
            $io->warning('Finalization was cancelled. Setup remains resumable and is not locked.');

            return self::FAILURE;
        }

        $completedId = $finalizer->finalize();
        if (!hash_equals($installationId, $completedId)) {
            throw new \RuntimeException('Installation identity changed during finalization.');
        }
        $io->success('Installation completed and setup is permanently locked. Configure production SMTP only through the authenticated HTTPS administration UI.');

        return self::SUCCESS;
    }

    private function ask(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, ?string $default = null): string
    {
        $answer = $helper->ask($input, $output, new Question($prompt, $default));
        if (!\is_string($answer) || '' === trim($answer)) {
            throw new \InvalidArgumentException('Required installer input was omitted.');
        }

        return trim($answer);
    }

    private function askInt(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt, int $default): int
    {
        $answer = $helper->ask($input, $output, new Question($prompt, (string) $default));
        if (!\is_string($answer) && !\is_int($answer)) {
            throw new \InvalidArgumentException('Numeric installer input was omitted.');
        }
        $value = filter_var($answer, FILTER_VALIDATE_INT);
        if (false === $value) {
            throw new \InvalidArgumentException('Numeric installer input is invalid.');
        }

        return $value;
    }

    private function askSecret(QuestionHelper $helper, InputInterface $input, OutputInterface $output, string $prompt): string
    {
        $answer = $helper->ask($input, $output, (new Question($prompt))->setHidden(true)->setHiddenFallback(false));
        if (!\is_string($answer) || '' === $answer) {
            throw new \InvalidArgumentException('Required secret installer input was omitted.');
        }

        return $answer;
    }
}
