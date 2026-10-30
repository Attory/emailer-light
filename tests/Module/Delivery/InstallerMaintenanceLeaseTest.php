<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use PHPUnit\Framework\TestCase;

final class InstallerMaintenanceLeaseTest extends TestCase
{
    public function testOnlyTheInheritedExactRootLeaseCanBridgeTheWholeHostOperation(): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('Needs a root fixture for privileged descriptor transfer.');
        }
        $directory = '/run/emailer-maintenance-lease-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $key = 'emailer-maintenance-operation';
        $suffix = str_replace('/', '_', substr(base64_encode(hash('sha256', $key, true)), 0, 7));
        $path = $directory.'/sf.'.$key.'.'.$suffix.'.lock';
        $lock = fopen($path, 'x+b');
        self::assertIsResource($lock);
        flock($lock, LOCK_EX);
        $script = <<<'PHP'
require '/app/vendor/autoload.php';
$factory = new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore($argv[1]));
try {
    $maintenance = new \App\Shared\MaintenanceOperationLock($factory);
    $maintenance->acquire();
    $maintenance->acquire();
    $maintenance->release();
    $maintenance->release();
    echo 'bridged';
} catch (\Throwable) {
    echo 'refused';
}
PHP;
        try {
            $environment = ['PATH' => '/usr/bin:/bin', 'LOCK_DSN' => 'flock://'.$directory, 'EMAILER_INSTALLER_MAINTENANCE_FD' => '3'];
            self::assertSame('bridged', $this->runLeaseChild($script, $directory, $environment, $lock));
            $other = fopen($path, 'r+b');
            self::assertIsResource($other);
            try {
                // Child release/destruction must not unlock the parent's lease.
                self::assertFalse(flock($other, LOCK_EX | LOCK_NB));
                self::assertSame('refused', $this->runLeaseChild($script, $directory, $environment, $other));
                self::assertSame('refused', $this->runLeaseChild($script, $directory, ['PATH' => '/usr/bin:/bin'], $other));
            } finally {
                fclose($other);
            }
        } finally {
            fclose($lock);
            unlink($path);
            rmdir($directory);
        }
    }

    /** @param array<string, string> $environment
     * @param resource $descriptor
     */
    private function runLeaseChild(string $script, string $directory, array $environment, mixed $descriptor): string
    {
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $script, $directory], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w'], 3 => $descriptor], $pipes, '/app', $environment);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Lease fixture process could not start.');
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        proc_close($process);

        return false === $output ? '' : $output;
    }
}
