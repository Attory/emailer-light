<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\LegacyDatabaseEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\DependencyInjection\EnvVarProcessor;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;

final class LegacyDatabaseEnvironmentTest extends TestCase
{
    public function testEncodedCredentialsSurviveLegacyResolutionAndStayLiteralInNewRuntime(): void
    {
        $root = sys_get_temp_dir().'/emailer-legacy-dsn-'.bin2hex(random_bytes(8));
        mkdir($root.'/config/packages', 0o700, true);
        $file = $root.'/config/packages/doctrine.yaml';
        $values = ['DATABASE_URL' => 'mysql://root:synthetic%25%40password@localhost/db?unix_socket=%2Frun%2Fmysqld%2Fmysqld.sock', 'APP_SECRET' => 'synthetic%unchanged'];
        try {
            file_put_contents($file, "doctrine:\n    dbal:\n        url: '%env(resolve:DATABASE_URL)%'\n");
            $legacy = LegacyDatabaseEnvironment::forRelease($root, $values);
            $processor = new EnvVarProcessor(new Container(new ParameterBag()));
            self::assertSame($values['DATABASE_URL'], $processor->getEnv('resolve', 'DATABASE_URL', static fn () => $legacy['DATABASE_URL']));
            self::assertSame($values['APP_SECRET'], $legacy['APP_SECRET']);
            file_put_contents($file, "doctrine:\n    dbal:\n        url: '%env(DATABASE_URL)%'\n");
            self::assertSame($values, LegacyDatabaseEnvironment::forRelease($root, $values));
        } finally {
            unlink($file);
            rmdir($root.'/config/packages');
            rmdir($root.'/config');
            rmdir($root);
        }
    }
}
