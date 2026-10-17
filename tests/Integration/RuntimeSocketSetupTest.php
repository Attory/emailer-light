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
        self::assertSame('emailer_test', $baseline->getDatabase());
        self::assertSame('1', (string) $baseline->fetchOne('SELECT 1'));
        $params = $baseline->getParams();
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
            self::assertStringNotContainsString((string) $params['password'], $exception->getMessage());
        } finally {
            $connection->close();
        }
    }

    public function testManualSetupCannotSubstituteTheConfiguredSocket(): void
    {
        self::bootKernel();
        $baseline = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $baseline);
        $params = $baseline->getParams();
        $params['unix_socket'] = '/run/emailer-database/mysql.sock';
        $connection = DriverManager::getConnection($params);
        $factory = DependencyFactory::fromConnection(new ExistingConfiguration(new Configuration()), new ExistingConnection($connection));
        $lifecycle = $this->createMock(SetupLifecycle::class);
        $lifecycle->expects(self::never())->method('mutate');
        self::assertIsString($params['host']);
        self::assertIsString($params['dbname']);
        self::assertIsString($params['user']);
        self::assertIsString($params['password']);
        $port = $params['port'] ?? 3306;
        self::assertTrue(is_numeric($port));
        $this->expectExceptionMessage('Database configuration does not match the external runtime configuration.');
        (new DatabaseSetup($connection, $factory, $lifecycle))->configure(new DatabaseInput($params['host'], (int) $port, $params['dbname'], $params['user'], $params['password']));
    }
}
