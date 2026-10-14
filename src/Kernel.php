<?php

declare(strict_types=1);

namespace App;

use App\Infrastructure\Mail\TestSmtpPolicy;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        if ('test' === $environment) {
            TestSmtpPolicy::assertSafe(self::environmentValue('MAILER_DSN'));
        }

        parent::__construct($environment, $debug);
    }

    public function getCacheDir(): string
    {
        return $this->runtimeRoot().'/cache/'.$this->environment;
    }

    public function getLogDir(): string
    {
        return $this->runtimeRoot().'/log';
    }

    private static function environmentValue(string $name): string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) ? $value : '';
    }

    private function runtimeRoot(): string
    {
        $configured = self::environmentValue('EMAILER_RUNTIME_DIR');
        if ('' === $configured) {
            $configured = self::environmentValue('EMAILER_PRIVATE_DIR');
        }

        return '' !== $configured
            ? rtrim($configured, '/\\')
            : \dirname(__DIR__).'/var';
    }
}
