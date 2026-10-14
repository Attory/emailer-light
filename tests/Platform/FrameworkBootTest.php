<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class FrameworkBootTest extends KernelTestCase
{
    public function testSymfonyKernelBootsInTheTestEnvironment(): void
    {
        $kernel = self::bootKernel();

        self::assertInstanceOf(KernelInterface::class, $kernel);
        self::assertSame('test', $kernel->getEnvironment());
        self::assertFalse($kernel->isDebug());
    }

    public function testKernelRejectsAnExternalSmtpDestinationBeforeBoot(): void
    {
        $hadPreviousDsn = \array_key_exists('MAILER_DSN', $_SERVER);
        $previousDsn = $_SERVER['MAILER_DSN'] ?? null;
        $_SERVER['MAILER_DSN'] = 'smtp://192.0.2.1:25';

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('approved local Mailpit endpoint');

            new Kernel('test', false);
        } finally {
            if ($hadPreviousDsn) {
                $_SERVER['MAILER_DSN'] = $previousDsn;
            } else {
                unset($_SERVER['MAILER_DSN']);
            }
        }
    }
}
