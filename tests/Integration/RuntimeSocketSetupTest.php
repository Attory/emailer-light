<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Setup\Application\Contract\SetupLifecycle;
use App\Module\Setup\Application\DatabaseInput;
use App\Module\Setup\Application\DatabaseSetup;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RuntimeSocketSetupTest extends KernelTestCase
{
    public function testGuidedSetupNeverFallsBackToReachableTcpWhenItsSocketIsUnavailable(): void
    {
        self::bootKernel();
        $baseline = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $baseline);
        self::assertMatchesRegularExpression('/^emailer(?:_(?:ripe|light))?_test$/D', (string) $baseline->getDatabase());
        $alive = $baseline->fetchOne('SELECT 1');
        self::assertTrue(\is_string($alive) || \is_int($alive));
        self::assertSame('1', (string) $alive);
        $params = $this->databaseParams($baseline);
        $params['unix_socket'] = sys_get_temp_dir().'/emailer-unavailable-'.bin2hex(random_bytes(12)).'.sock';
        $connection = DriverManager::getConnection($params);
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $lifecycle = new class implements SetupLifecycle {
            public function mutate(\Closure $operation): mixed
            {
                return $operation();
            }

            public function finalize(\Closure $operation): mixed
            {
                throw new \LogicException('Finalization is outside this connection check.');
            }
        };
        try {
            (new DatabaseSetup($connection, $factory, $lifecycle))->configureRuntime();
            self::fail('Guided setup silently used TCP instead of its configured socket.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Database connection verification failed.', $exception->getMessage());
            self::assertStringNotContainsString($params['password'], $exception->getMessage());
        } finally {
            $connection->close();
        }
    }

    public function testManualSetupCannotSubstituteTheConfiguredSocket(): void
    {
        self::bootKernel();
        $baseline = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $baseline);
        $params = $this->databaseParams($baseline);
        $params['unix_socket'] = '/run/emailer-database/mysql.sock';
        $connection = DriverManager::getConnection($params);
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $lifecycle = $this->createMock(SetupLifecycle::class);
        $lifecycle->expects(self::never())->method('mutate');
        $this->expectExceptionMessage('Database configuration does not match the external runtime configuration.');
        (new DatabaseSetup($connection, $factory, $lifecycle))->configure(new DatabaseInput($params['host'], $params['port'], $params['dbname'], $params['user'], $params['password']));
    }

    /** @return array{driver: 'pdo_mysql', host: string, port: int, dbname: string, user: string, password: string, charset: string} */
    private function databaseParams(Connection $connection): array
    {
        $params = $connection->getParams();
        $host = $params['host'] ?? null;
        $database = $params['dbname'] ?? null;
        $user = $params['user'] ?? null;
        $password = $params['password'] ?? null;
        $port = $params['port'] ?? 3306;
        self::assertIsString($host);
        self::assertIsString($database);
        self::assertIsString($user);
        self::assertIsString($password);
        self::assertTrue(is_numeric($port));

        return ['driver' => 'pdo_mysql', 'host' => $host, 'port' => (int) $port, 'dbname' => $database, 'user' => $user, 'password' => $password, 'charset' => 'utf8mb4'];
    }
}
