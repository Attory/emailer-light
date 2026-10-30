<?php

declare(strict_types=1);

namespace App\Tests\Module\Delivery;

use App\Shared\DeliveryControlFiles;
use PHPUnit\Framework\TestCase;

final class DeliveryControlFilesTest extends TestCase
{
    private string $directory;
    private const INSTALLATION = '01900000-0000-7000-8000-000000000001';
    private const FIRST = '01900000-0000-7000-8000-000000000002';
    private const SECOND = '01900000-0000-7000-8000-000000000003';

    protected function setUp(): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('The filesystem hold authority fixture needs a root container.');
        }
        $this->directory = '/run/emailer-control-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o755);
    }

    protected function tearDown(): void
    {
        if (!isset($this->directory)) {
            return;
        }
        $paths = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($paths as $path) {
            if (!$path instanceof \SplFileInfo) {
                throw new \LogicException('Unexpected fixture cleanup entry.');
            }
            if ($path->isDir() && !$path->isLink()) {
                rmdir($path->getPathname());
            } else {
                unlink($path->getPathname());
            }
        }
        rmdir($this->directory);
    }

    public function testNewOwnerPauseSurvivesCompletionAndOverlappingHolds(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->hold(self::FIRST, 'host', ['source' => 'synthetic']);
        $files->hold(self::SECOND, 'manual', ['source' => 'independent']);
        $captured = $files->status()['generation'];
        $files->pause();
        $files->phase(self::FIRST, 'released');
        $state = $files->status();
        self::assertSame('paused', $state['owner']);
        self::assertGreaterThan($captured, $state['generation']);
        self::assertSame([self::SECOND], array_column($state['holds'], 'operation_id'));
        $files->phase(self::SECOND, 'released');
        self::assertSame('paused', $files->status()['owner']);
        $files->enable();
        self::assertSame('enabled', $files->status()['owner']);
        $files->hold(self::FIRST, 'host', ['source' => 'synthetic']);
        self::assertSame([], $files->status()['holds']);
    }

    public function testRecoveryAndRepeatedPrepareCannotEraseUnknownIntent(): void
    {
        $files = $this->files();
        self::assertSame('legacy', $files->status()['owner']);
        $files->prepare(1000, 1000, 'unknown');
        $files->hold(self::FIRST, 'native', ['source' => 'synthetic']);
        $files->phase(self::FIRST, 'failed');
        $files->phase(self::FIRST, 'recovered');
        $files->prepare(1000, 1000, 'enabled');
        self::assertSame('unknown', $files->status()['owner']);
        self::assertSame('recovered', $files->status()['holds'][0]['phase']);
        $this->expectException(\RuntimeException::class);
        $files->enable();
    }

    public function testApplicationCanPauseButCannotRetireRootFence(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->hold(self::FIRST, 'host', []);
        self::assertTrue(posix_seteuid(1000));
        try {
            $files->pause();
            self::assertSame('paused', $files->status()['owner']);
            $this->expectException(\RuntimeException::class);
            $files->phase(self::FIRST, 'released');
        } finally {
            posix_seteuid(0);
        }
    }

    public function testTransferRetainsLatestIntentAndGeneration(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->pause();
        $before = $files->status();
        $files->transferOwner(1001, 1001);
        self::assertSame($before, $files->status());
    }

    public function testUnsafeOrWrongIdentityEvidenceIsNotAbsence(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->hold(self::FIRST, 'host', []);
        $path = $this->directory.'/delivery-control/'.self::INSTALLATION.'/holds/'.self::FIRST.'.json';
        chmod($path, 0o666);
        $this->expectException(\RuntimeException::class);
        $files->status();
    }

    public function testInterruptedCustodyTransferReplaysWithoutRestoringAnOlderIntent(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->pause();
        $before = $files->status();
        $base = $this->directory.'/delivery-control/'.self::INSTALLATION;
        file_put_contents($base.'/owner-transfer.json', json_encode(['format' => 'emailer-owner-transfer-v1', 'installation_uuid' => self::INSTALLATION, 'phase' => 'active', 'source_uid' => 1000, 'target_uid' => 1001, 'target_gid' => 1001], JSON_THROW_ON_ERROR));
        chmod($base.'/owner-transfer.json', 0o644);
        chown($base.'/owner', 0);
        chown($base.'/owner/intent.json', 1001);
        $files->transferOwner(1001, 1001);
        self::assertSame($before, $files->status());
        self::assertSame(1001, fileowner($base.'/owner'));
    }

    public function testWrongIdentityOrLinkedOwnerRecordCannotBecomeAResume(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'paused');
        $base = $this->directory.'/delivery-control/'.self::INSTALLATION;
        $sentinel = $this->directory.'/sentinel';
        file_put_contents($sentinel, 'unrelated');
        unlink($base.'/owner/intent.json');
        symlink($sentinel, $base.'/owner/intent.json');
        try {
            $files->transferOwner(1001, 1001);
            self::fail('Linked intent must fail closed.');
        } catch (\RuntimeException) {
            self::assertSame('unrelated', file_get_contents($sentinel));
            self::assertSame(0, fileowner($sentinel));
        }
    }

    public function testInterruptedBootstrapReplaysOnlyItsProtectedInitialization(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $base = $this->directory.'/delivery-control/'.self::INSTALLATION;
        $bootstrap = json_decode((string) file_get_contents($base.'/owner-bootstrap.json'), true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($bootstrap);
        $bootstrap['phase'] = 'active';
        file_put_contents($base.'/owner-bootstrap.json', json_encode($bootstrap, JSON_THROW_ON_ERROR));
        chown($base.'/owner', 0);
        unlink($base.'/owner/intent.json');
        unlink($base.'/owner/intent.lock');
        $files->prepare(1000, 1000, 'unknown');
        self::assertSame('enabled', $files->status()['owner']);
        $files->pause();
        $before = $files->status();
        $bootstrap['phase'] = 'active';
        file_put_contents($base.'/owner-bootstrap.json', json_encode($bootstrap, JSON_THROW_ON_ERROR));
        $files->prepare(1000, 1000, 'enabled');
        self::assertSame($before, $files->status());
    }

    public function testAcknowledgedPauseWaitsForAnAdmittedClaimThenBlocksTheNextClaim(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $lease = $files->claimLease();
        $script = <<<'PHP'
require $argv[1];
try {
    if (!posix_setgid(1000) || !posix_setuid(1000)) { exit(2); }
    $files = new App\Shared\DeliveryControlFiles($argv[2], $argv[3]);
    fwrite(STDOUT, "attempting\n"); fflush(STDOUT);
    $files->pause();
    fwrite(STDOUT, "acknowledged\n");
} catch (Throwable) { exit(1); }
PHP;
        $child = proc_open([PHP_BINARY, '-r', $script, \dirname(__DIR__, 3).'/vendor/autoload.php', $this->directory.'/private', self::INSTALLATION], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes);
        if (!\is_resource($child) || !isset($pipes[1])) {
            $lease->release();
            throw new \RuntimeException('Fixture process unavailable.');
        }
        stream_set_timeout($pipes[1], 3);
        try {
            self::assertSame("attempting\n", fgets($pipes[1]));
            $read = [$pipes[1]];
            $write = $except = [];
            self::assertSame(0, stream_select($read, $write, $except, 0, 200000));
            self::assertSame('enabled', $files->status()['owner']);
            $lease->release();
            self::assertSame("acknowledged\n", fgets($pipes[1]));
            self::assertSame('paused', $files->status()['owner']);
            self::assertSame(1, $files->status()['generation']);
            $this->expectException(\DomainException::class);
            $files->claimLease();
        } finally {
            $lease->release();
            fclose($pipes[1]);
            self::assertSame(0, proc_close($child));
        }
    }

    private function files(): DeliveryControlFiles
    {
        return new DeliveryControlFiles($this->directory.'/private', self::INSTALLATION);
    }
}
