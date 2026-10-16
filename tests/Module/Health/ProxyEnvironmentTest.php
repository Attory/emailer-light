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
