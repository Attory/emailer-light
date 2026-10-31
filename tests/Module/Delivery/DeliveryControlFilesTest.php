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
        $this->expectException(\App\Shared\DeliveryControlUnavailable::class);
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

    public function testLegacyZeroReconciliationPreservesNewerExplicitOwnerGenerations(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->invalidateUnprovenLegacyIntent();
        self::assertSame('unknown', $files->status()['owner']);
        self::assertSame(1, $files->status()['generation']);
        $files->enable(); // An explicit reviewed Resume is a newer authority.
        $enabled = $files->status();
        $files->invalidateUnprovenLegacyIntent();
        self::assertSame($enabled, $files->status());
        $files->pause();
        $paused = $files->status();
        $files->invalidateUnprovenLegacyIntent();
        self::assertSame($paused, $files->status());
    }

    public function testSignedInstallerProbeRunsAsTheActualNonrootAccountWhileHeld(): void
    {
        $files = $this->files();
        $mask = umask(0o077);
        try {
            $files->prepare(1000, 1000, 'enabled');
        } finally {
            umask($mask);
        }
        $files->hold(self::FIRST, 'host', []);
        foreach (['application', 'worker', 'scheduler'] as $role) {
            $probe = $this->actor('probe', $role);
            self::assertTrue($probe['delivery_control_access']);
            self::assertSame(1000, $probe['nonroot_uid']);
            self::assertTrue($probe['held_claim_rejected']);
            self::assertTrue($probe['owner_writable']);
            self::assertTrue($probe['root_holds_read_only']);
        }
    }

    public function testRestrictiveInstallerUmaskPublishesUsableLeastPrivilegeControls(): void
    {
        $files = $this->files();
        $mask = umask(0o077);
        try {
            $files->prepare(1000, 1000, 'enabled');
        } finally {
            umask($mask);
        }
        $root = $this->directory.'/delivery-control/'.self::INSTALLATION;
        foreach ([$this->directory.'/delivery-control', $root, $root.'/holds'] as $path) {
            clearstatcache(true, $path);
            self::assertSame(0o755, fileperms($path) & 0o777);
            self::assertSame(0, fileowner($path));
            self::assertSame(0, filegroup($path));
        }
        foreach (['application', 'worker', 'scheduler'] as $role) {
            self::assertSame(['uid' => 1000, 'configured' => true, 'owner' => 'enabled', 'holds' => 0, 'claim' => true, 'owner_writable' => true, 'holds_writable' => false], $this->actor('inspect', $role));
        }
        $files->hold(self::FIRST, 'host', []);
        $files->hold(self::SECOND, 'manual', []);
        self::assertFalse($this->actor('inspect', 'worker')['claim']);
        self::assertSame('paused', $this->actor('pause', 'application')['owner']);
        $paused = $files->status();
        $files->phase(self::FIRST, 'released');
        self::assertSame(1, $this->actor('inspect', 'scheduler')['holds']);
        $files->phase(self::SECOND, 'released');
        self::assertSame('paused', $this->actor('inspect', 'worker')['owner']);
        self::assertSame($paused['generation'], $files->status()['generation']);
        self::assertTrue($this->actor('enable', 'application')['claim']);
    }

    public function testPublishedRestrictiveNamespaceIsBlockedAndRepairPreservesHoldsAndPause(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $files->hold(self::FIRST, 'host', []);
        $files->hold(self::SECOND, 'manual', []);
        $files->pause();
        $before = $files->status();
        $root = $this->directory.'/delivery-control/'.self::INSTALLATION;
        // Actual published-package footprint under0077. No state contents edited.
        foreach ([$root.'/holds', $root, $this->directory.'/delivery-control'] as $path) {
            chmod($path, 0o700);
        }
        foreach (['application', 'worker', 'scheduler'] as $role) {
            self::assertSame(['uid' => 1000, 'blocked' => 'delivery_control_unavailable'], $this->actor('inspect', $role));
        }
        $mask = umask(0o077);
        try {
            $files->prepare(1000, 1000, 'unknown');
        } finally {
            umask($mask);
        }
        self::assertSame($before, $files->status());
        self::assertSame('paused', $this->actor('inspect', 'worker')['owner']);
        self::assertSame(2, $this->actor('inspect', 'scheduler')['holds']);
    }

    public function testOpaqueZeroIntentBecomesUnknownButNewerExplicitIntentSurvivesRepair(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $root = $this->directory.'/delivery-control/'.self::INSTALLATION;
        chmod($root, 0o700);
        $files->prepare(1000, 1000, 'unknown');
        self::assertSame('unknown', $files->status()['owner']);
        self::assertSame(1, $files->status()['generation']);
        $files->enable();
        $explicit = $files->status();
        chmod($root, 0o700);
        $files->prepare(1000, 1000, 'unknown');
        self::assertSame($explicit, $files->status());
    }

    public function testInterruptedAccessRepairRetainsAmbiguousDatabasePause(): void
    {
        $files = $this->files();
        $files->prepare(1000, 1000, 'enabled');
        $root = $this->directory.'/delivery-control/'.self::INSTALLATION;
        // Crash after permission publication but before invalidation: durable
        // active repair receipt must survive a retry whose current DB is enabled.
        file_put_contents($root.'/access-repair.json', json_encode(['format' => 'emailer-control-access-repair-v1', 'installation_uuid' => self::INSTALLATION, 'phase' => 'active', 'unknown_prior' => true], JSON_THROW_ON_ERROR));
        chmod($root.'/access-repair.json', 0o644);
        $files->prepare(1000, 1000, 'enabled');
        self::assertSame('unknown', $files->status()['owner']);
        self::assertFalse($this->actor('inspect', 'worker')['claim']);
        $files->enable();
        $newer = $files->status();
        $files->prepare(1000, 1000, 'unknown');
        self::assertSame($newer, $files->status());
    }

    public function testUnreadableParentAndTamperedOrMissingHoldsNeverBecomeLegacy(): void
    {
        $files = $this->files();
        self::assertFalse($this->actor('inspect', 'application')['configured']);
        $files->prepare(1000, 1000, 'enabled');
        $root = $this->directory.'/delivery-control/'.self::INSTALLATION;
        chmod($this->directory, 0o700);
        self::assertSame(['uid' => 1000, 'blocked' => 'delivery_control_unavailable'], $this->actor('inspect', 'worker'));
        chmod($this->directory, 0o755);
        rmdir($root.'/holds');
        self::assertSame(['uid' => 1000, 'blocked' => 'delivery_control_unavailable'], $this->actor('inspect', 'scheduler'));
        mkdir($root.'/holds', 0o755);
        $files->hold(self::FIRST, 'host', []);
        chmod($root.'/holds/'.self::FIRST.'.json', 0o666);
        self::assertSame(['uid' => 1000, 'blocked' => 'delivery_control_unavailable'], $this->actor('inspect', 'application'));
    }

    /** @return array<string, mixed> */
    private function actor(string $action, string $role): array
    {
        $script = <<<'PHP'
require $argv[1].'/src/Shared/DeliveryControlFiles.php';
require $argv[1].'/src/Shared/DeliveryControlUnavailable.php';
require $argv[1].'/src/Shared/DeliveryIntentLease.php';
if (!in_array($argv[5], ['application','worker','scheduler'], true) || !posix_setgid(1000) || !posix_setuid(1000) || posix_geteuid() !== 1000) { exit(3); }
if ($argv[4] === 'probe') {
 $source=$argv[1]; $argv=['control-access.php',$source,$argv[2].'/private',$argv[3]]; $argc=4;
 require $source.'/installer/control-access.php'; exit(0);
}
$f = new App\Shared\DeliveryControlFiles($argv[2].'/private', $argv[3]);
try {
 if ($argv[4] === 'pause') { $f->pause(); }
 if ($argv[4] === 'enable') { $f->enable(); }
 $state = $f->status(); $claim = false;
 try { $lease=$f->claimLease(); $lease->release(); $claim=true; } catch (App\Shared\DeliveryControlUnavailable $e) { throw $e; } catch (DomainException) {}
 $root=$argv[2].'/delivery-control/'.$argv[3];
 echo json_encode(['uid'=>posix_geteuid(),'configured'=>$state['configured'],'owner'=>$state['owner'],'holds'=>count($state['holds']),'claim'=>$claim,'owner_writable'=>is_writable($root.'/owner'),'holds_writable'=>is_writable($root.'/holds')],JSON_THROW_ON_ERROR);
} catch (App\Shared\DeliveryControlUnavailable) { echo json_encode(['uid'=>posix_geteuid(),'blocked'=>'delivery_control_unavailable'],JSON_THROW_ON_ERROR); }
PHP;
        $source = \dirname(__DIR__, 3);
        $process = proc_open([PHP_BINARY, '-r', $script, $source, $this->directory, self::INSTALLATION, $action, $role], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        $result = json_decode($output, true, 16, JSON_THROW_ON_ERROR);
        self::assertIsArray($result);

        $record = [];
        foreach ($result as $key => $value) {
            self::assertIsString($key);
            $record[$key] = $value;
        }

        return $record;
    }

    private function files(): DeliveryControlFiles
    {
        return new DeliveryControlFiles($this->directory.'/private', self::INSTALLATION);
    }
}
