<?php

declare(strict_types=1);

namespace App\Module\Release\Presentation\Cli;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'emailer:release:check', description: 'Report redacted runtime, migration, and release-pointer compatibility.')]
final class CheckReleaseCommand extends Command
{
    public function __construct(private readonly Connection $connection, private readonly string $releasesDirectory, private readonly string $version)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $migration = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
        $pointer = '' !== $this->releasesDirectory && is_link($this->releasesDirectory.'/current') ? readlink($this->releasesDirectory.'/current') : null;
        $output->writeln(json_encode(['version' => $this->version, 'schema_version' => \is_string($migration) ? substr($migration, -14) : 'unknown', 'immutable_pointer' => \is_string($pointer)], JSON_THROW_ON_ERROR));

        return \is_string($migration) ? Command::SUCCESS : Command::FAILURE;
    }
}
