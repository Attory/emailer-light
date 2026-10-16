<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Symfony\Component\Dotenv\Dotenv;

/** Fence only this application's dedicated database identity, including shared-FPM writers. */
final readonly class NativeWriterFence
{
    public function __construct(private Connection $connection, private string $runtimeConfigurationFile)
    {
    }

    /** @return array{name: string, host: string, locked: bool} */
    public function identify(): array
    {
        NativeUpdateJournal::regular($this->runtimeConfigurationFile);
        $values = (new Dotenv())->parse(NativeUpdateJournal::text(file_get_contents($this->runtimeConfigurationFile)));
        $parameters = (new DsnParser(['mysql' => 'pdo_mysql']))->parse(NativeUpdateJournal::text($values['DATABASE_URL'] ?? null));
        $application = DriverManager::getConnection($parameters);
        try {
            $identity = NativeUpdateJournal::text($application->fetchOne('SELECT CURRENT_USER()'));
            if ($application->getDatabase() !== $this->connection->getDatabase() || $identity === $this->connection->fetchOne('SELECT CURRENT_USER()') || $application->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i') !== $this->connection->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i')) {
                throw new \RuntimeException('Update needs a separate maintenance identity on the same database server.');
            }
        } finally {
            $application->close();
        }
        $separator = strrpos($identity, '@');
        if (false === $separator) {
            throw new \RuntimeException('The application database identity cannot be identified.');
        }
        $account = ['name' => substr($identity, 0, $separator), 'host' => substr($identity, $separator + 1), 'locked' => false];
        if (1 !== NativeUpdateJournal::integer($this->connection->fetchOne('SELECT COUNT(*) FROM mysql.global_priv WHERE User=?', [$account['name']]))) {
            throw new \RuntimeException('The database username is shared. A dedicated application database account is required before update.');
        }
        $database = NativeUpdateJournal::text($this->connection->getDatabase());
        foreach ($this->connection->fetchFirstColumn('SHOW GRANTS FOR '.$this->quoted($account)) as $grant) {
            $grant = NativeUpdateJournal::text($grant);
            if (str_starts_with($grant, 'GRANT USAGE ON *.* TO ')) {
                continue;
            }
            if (!str_contains($grant, ' ON `'.str_replace('`', '``', $database).'`.* TO ') || str_contains($grant, 'WITH GRANT OPTION')) {
                throw new \RuntimeException('The application database account has shared privileges; update will not lock it.');
            }
        }
        $definition = $this->connection->fetchAssociative('SHOW CREATE USER '.$this->quoted($account));
        if (false === $definition) {
            throw new \RuntimeException('Database account-lock authority is unavailable.');
        }
        $account['locked'] = str_contains(NativeUpdateJournal::text(reset($definition)), 'ACCOUNT LOCK');

        return $account;
    }

    /** @param array{name: string, host: string, locked: bool} $account */
    public function close(array $account): void
    {
        $this->connection->executeStatement('ALTER USER '.$this->quoted($account).' ACCOUNT LOCK');
        foreach ($this->connection->fetchFirstColumn('SELECT ID FROM information_schema.PROCESSLIST WHERE USER=? AND ID<>CONNECTION_ID()', [$account['name']]) as $id) {
            if ((!\is_int($id) && !\is_string($id)) || 1 !== preg_match('/^[0-9]+$/D', (string) $id)) {
                throw new \RuntimeException('An application connection cannot be fenced safely.');
            }
            $this->connection->executeStatement('KILL CONNECTION '.$id);
        }
        if (false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.PROCESSLIST WHERE USER=? AND ID<>CONNECTION_ID()', [$account['name']])) {
            throw new \RuntimeException('An application connection is still active. Keep delivery held and retry recovery.');
        }
    }

    /** @param array{name: string, host: string, locked: bool} $account */
    public function restore(array $account): void
    {
        $this->connection->executeStatement('ALTER USER '.$this->quoted($account).($account['locked'] ? ' ACCOUNT LOCK' : ' ACCOUNT UNLOCK'));
    }

    /** @param array{name: string, host: string, locked: bool} $account */
    private function quoted(array $account): string
    {
        return $this->connection->quote($account['name']).'@'.$this->connection->quote($account['host']);
    }
}
