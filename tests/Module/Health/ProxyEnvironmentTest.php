<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Yaml\Yaml;

final class ProxyEnvironmentTest extends TestCase
{
    public function testDatabaseUrlPreservesPercentEncodedCredentialsAndSocketPaths(): void
    {
        $configuration = Yaml::parseFile(__DIR__.'/../../../config/packages/doctrine.yaml');
        self::assertIsArray($configuration);
        self::assertIsArray($configuration['doctrine']);
        self::assertIsArray($configuration['doctrine']['dbal']);
        self::assertSame('%env(DATABASE_URL)%', $configuration['doctrine']['dbal']['url']);
        $previous = getenv('DATABASE_URL');
        $previousEnv = $_ENV['DATABASE_URL'] ?? null;
        $previousServer = $_SERVER['DATABASE_URL'] ?? null;
        $url = 'mysql://example:synthetic%25password@localhost/database?unix_socket=%2Frun%2Fmysqld%2Fmysqld.sock';
        try {
            putenv('DATABASE_URL='.$url);
            $_ENV['DATABASE_URL'] = $_SERVER['DATABASE_URL'] = $url;
            $processor = new EnvVarProcessor(new Container(new ParameterBag()));
            self::assertSame($url, $processor->getEnv('string', 'DATABASE_URL', static fn () => null));
        } finally {
            putenv(false === $previous ? 'DATABASE_URL' : 'DATABASE_URL='.$previous);
            unset($_ENV['DATABASE_URL'], $_SERVER['DATABASE_URL']);
            if (null !== $previousEnv) {
                $_ENV['DATABASE_URL'] = $previousEnv;
            }
            if (null !== $previousServer) {
                $_SERVER['DATABASE_URL'] = $previousServer;
            }
        }
    }

    public function testDirectFastCgiPreservesExplicitEmptyProxyListAndLegacyDefault(): void
    {
        $configuration = Yaml::parseFile(__DIR__.'/../../../config/services.yaml', Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($configuration);
        self::assertIsArray($configuration['parameters']);
        $parameters = $configuration['parameters'];
        self::assertIsString($parameters['env(TRUSTED_PROXIES)']);
        $container = new Container(new ParameterBag());
        $container->setParameter('env(TRUSTED_PROXIES)', $parameters['env(TRUSTED_PROXIES)']);
        $processor = new EnvVarProcessor($container);
        $previous = getenv('TRUSTED_PROXIES');
        try {
            putenv('TRUSTED_PROXIES=');
            self::assertSame('%env(TRUSTED_PROXIES)%', $parameters['emailer.trusted_proxies']);
            self::assertSame('', $processor->getEnv('string', 'TRUSTED_PROXIES', static fn () => null));
            putenv('TRUSTED_PROXIES');
            self::assertSame('127.0.0.1,::1', $processor->getEnv('string', 'TRUSTED_PROXIES', static fn () => null));
        } finally {
            putenv(false === $previous ? 'TRUSTED_PROXIES' : 'TRUSTED_PROXIES='.$previous);
        }
    }
}
