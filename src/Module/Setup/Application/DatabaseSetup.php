<?php

declare(strict_types=1);

namespace App\Module\Setup\Application;

use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

final readonly class DatabaseSetup
{
    public function __construct(
        private Connection $connection,
        private DependencyFactory $migrations,
        private SetupLifecycle $lifecycle,
    ) {
    }

    /** Use the protected, already provisioned runtime; never ask for its password again. */
    public function configureRuntime(): void
    {
        $params = $this->connection->getParams();
        foreach (['host', 'dbname', 'user', 'password'] as $name) {
            if (!\is_string($params[$name] ?? null)) {
                throw new \RuntimeException('Protected database configuration is incomplete.');
            }
        }
        $port = $params['port'] ?? 3306;
        if (!is_numeric($port)) {
            throw new \RuntimeException('Protected database port is invalid.');
        }
        $this->configure(new DatabaseInput($params['host'], (int) $port, $params['dbname'], $params['user'], $params['password']));
    }

    public function configure(DatabaseInput $input): void
    {
        $params = $this->connection->getParams();
        $activeHost = $params['host'] ?? null;
        $configuredPort = $params['port'] ?? 3306;
        if (!\is_int($configuredPort) && !\is_string($configuredPort)) {
            throw new \RuntimeException('External database runtime configuration is invalid.');
        }
        $activePort = (int) $configuredPort;
        $activeDatabase = $params['dbname'] ?? null;
        $activeUsername = $params['user'] ?? null;

        if ($input->host !== $activeHost
            || $input->port !== $activePort
            || $input->database !== $activeDatabase
            || $input->username !== $activeUsername
        ) {
            throw new \RuntimeException('Database configuration does not match the external runtime configuration.');
        }

        $this->lifecycle->mutate(function () use ($input): void {
            $probe = DriverManager::getConnection([
                'driver' => 'pdo_mysql',
                'host' => $input->host,
                'port' => $input->port,
                'dbname' => $input->database,
                'user' => $input->username,
                'password' => $input->password,
                'charset' => 'utf8mb4',
                'driverOptions' => [\PDO::ATTR_TIMEOUT => 5],
            ]);

            try {
                $serverVersion = $probe->fetchOne('SELECT VERSION()');
                if (!\is_string($serverVersion) || !str_contains($serverVersion, 'MariaDB')) {
                    throw new \RuntimeException('The configured database is not a supported MariaDB server.');
                }
            } catch (\Throwable) {
                throw new \RuntimeException('Database connection verification failed.');
            } finally {
                $probe->close();
            }

            try {
                $this->migrations->getMetadataStorage()->ensureInitialized();
                $target = $this->migrations->getVersionAliasResolver()->resolveVersionAlias('latest');
                $plan = $this->migrations->getMigrationPlanCalculator()->getPlanUntilVersion($target);
                // MariaDB DDL commits implicitly; the schema cannot be one transaction.
                $configuration = (new MigratorConfiguration())->setAllOrNothing(false);
                $this->migrations->getMigrator()->migrate($plan, $configuration);
            } catch (\Throwable) {
                throw new \RuntimeException('Database migration failed.');
            } finally {
                // Doctrine may retain nested savepoints after an implicit DDL commit.
                // Reconnect before key initialization starts its own real transaction.
                $this->connection->close();
            }

            if (!$this->connection->createSchemaManager()->tablesExist(['installation_state'])) {
                throw new \RuntimeException('Database migration validation failed.');
            }
        });
    }
}
