<?php

declare(strict_types=1);

namespace App\Shared;

/** Independent owner intent and root-owned operation fences, outside restore roots. */
final readonly class DeliveryControlFiles
{
    private string $root;

    public function __construct(string $privateDirectory, private string $installation)
    {
        if (1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $installation) || (!str_starts_with($privateDirectory, '/') || 1 === preg_match('~(^|/)\.\.?(/|$)|//~', $privateDirectory))) {
            throw new \RuntimeException('Delivery control identity is invalid.');
        }
        $this->root = \dirname($privateDirectory).'/delivery-control/'.$installation;
    }

    /** @return array{configured: bool, owner: string, generation: int, holds: list<array<string, mixed>>} */
    public function status(): array
    {
        try {
            return $this->readStatus();
        } catch (\Throwable) {
            // No path, record content or previous exception enters diagnostics.
            throw new DeliveryControlUnavailable();
        }
    }

    /** @return array{configured: bool, owner: string, generation: int, holds: list<array<string, mixed>>} */
    private function readStatus(): array
    {
        if (!$this->namespaceAvailable()) {
            return ['configured' => false, 'owner' => 'legacy', 'generation' => 0, 'holds' => []];
        }
        $this->rootDirectory($this->root);
        $intent = $this->intent();
        if (0 !== posix_geteuid() && !is_writable($this->root.'/owner')) {
            throw new \RuntimeException('Application owner intent is not writable.');
        }
        $this->rootDirectory($this->root.'/holds');
        $stream = @opendir($this->root.'/holds');
        if (false === $stream) {
            throw new \RuntimeException('Delivery hold inventory is unreadable.');
        }
        $paths = [];
        try {
            while (false !== ($entry = readdir($stream))) {
                if (str_ends_with($entry, '.json')) {
                    $paths[] = $this->root.'/holds/'.$entry;
                    if (1024 < \count($paths)) {
                        throw new \RuntimeException('Delivery hold inventory exceeds its bound.');
                    }
                }
            }
        } finally {
            closedir($stream);
        }
        sort($paths, SORT_STRING);
        $holds = [];
        foreach ($paths as $path) {
            $hold = $this->read($path, 0, 0o644);
            $this->validateHold($hold, basename($path, '.json'));
            if ('released' !== $hold['phase']) {
                $holds[] = $hold;
            }
        }

        return ['configured' => true, 'owner' => $this->text($intent, 'owner'), 'generation' => $this->integer($intent, 'generation'), 'holds' => $holds];
    }

    /** Root calls this only after authenticating the source or proving a fresh installation. */
    public function prepare(int $uid, int $gid, string $priorIntent): void
    {
        $this->requireRoot();
        if (0 >= $uid || 0 > $gid || !\in_array($priorIntent, ['enabled', 'paused', 'unknown'], true)) {
            throw new \RuntimeException('Delivery intent custody is invalid.');
        }
        $base = \dirname($this->root);
        $this->rootDirectory(\dirname($base));
        $repair = $this->beginAccessRepair('unknown' === $priorIntent);
        foreach ([$base, $this->root, $this->root.'/holds'] as $directory) {
            $this->prepareControlDirectory($directory);
        }
        $transfer = $this->root.'/owner-transfer.json';
        if (file_exists($transfer) || is_link($transfer)) {
            $pending = $this->read($transfer, 0, 0o644);
            if ('active' === ($pending['phase'] ?? null)) {
                if (!\is_int($pending['target_uid'] ?? null) || !\is_int($pending['target_gid'] ?? null)) {
                    throw new \RuntimeException('Pending owner custody is invalid.');
                }
                $this->transferOwner($pending['target_uid'], $pending['target_gid']);
            }
        }
        $owner = $this->root.'/owner';
        $bootstrap = $this->root.'/owner-bootstrap.json';
        $initial = file_exists($bootstrap) || is_link($bootstrap) ? $this->read($bootstrap, 0, 0o644) : [];
        if (!file_exists($owner) && !is_link($owner) && [] === $initial) {
            $initial = ['format' => 'emailer-owner-bootstrap-v1', 'installation_uuid' => $this->installation, 'phase' => 'active', 'uid' => $uid, 'gid' => $gid, 'owner' => $priorIntent];
            $this->write($bootstrap, $initial, 0, 0, 0o644);
        }
        if ('active' === ($initial['phase'] ?? null)) {
            if ('emailer-owner-bootstrap-v1' !== ($initial['format'] ?? null) || $this->installation !== ($initial['installation_uuid'] ?? null) || !\is_int($initial['uid'] ?? null) || 0 >= $initial['uid'] || !\is_int($initial['gid'] ?? null) || 0 > $initial['gid'] || !\in_array($initial['owner'] ?? null, ['enabled', 'paused', 'unknown'], true)) {
                throw new \RuntimeException('Owner bootstrap receipt is invalid.');
            }
            if (!file_exists($owner) && !is_link($owner) && !mkdir($owner, 0o700)) {
                throw new \RuntimeException('Owner intent directory could not be prepared.');
            }
            $metadata = lstat($owner);
            if (false === $metadata || 0o040000 !== ($metadata['mode'] & 0o170000) || 0 !== ($metadata['mode'] & 0o077) || !\in_array($metadata['uid'], [0, $initial['uid']], true) || !chown($owner, 0) || !chgrp($owner, 0)) {
                throw new \RuntimeException('Owner bootstrap custody could not be fenced.');
            }
            $intent = $owner.'/intent.json';
            if (!file_exists($intent) && !is_link($intent)) {
                $this->write($intent, ['format' => 'emailer-owner-intent-v1', 'installation_uuid' => $this->installation, 'owner' => $initial['owner'], 'generation' => 0], $initial['uid'], $initial['gid'], 0o600);
            } else {
                $this->read($intent, $initial['uid'], 0o600); // Never overwrite an acknowledged intent.
            }
            $this->bootstrapLock($owner, $initial['uid'], $initial['gid']);
            if (!chown($owner, $initial['uid']) || !chgrp($owner, $initial['gid'])) {
                throw new \RuntimeException('Owner bootstrap publication failed.');
            }
            $this->syncDirectory($owner);
            $this->syncDirectory($this->root);
        }
        $custody = lstat($owner);
        if (false === $custody) {
            throw new \RuntimeException('Owner lock custody is unavailable.');
        }
        $this->readLock($owner.'/intent.lock', $custody['uid']);
        $this->intent();
        if ('active' === ($initial['phase'] ?? null)) {
            $initial['phase'] = 'complete';
            $this->write($bootstrap, $initial, 0, 0, 0o644);
        }
        if ([] !== $repair) {
            if (true === ($repair['unknown_prior'] ?? null)) {
                // The inaccessible source could acknowledge a DB-only Pause.
                // Never override a newer independently recorded owner generation.
                $this->invalidateUnprovenLegacyIntent();
            }
            $repair['phase'] = 'complete';
            $this->write($this->root.'/access-repair.json', $repair, 0, 0, 0o644);
        }
    }

    /** Preserve the current record, not a captured generation, across service-account transfer. */
    public function transferOwner(int $uid, int $gid): void
    {
        $this->requireRoot();
        if (0 >= $uid || 0 > $gid) {
            throw new \RuntimeException('Target owner intent custody is invalid.');
        }
        $this->rootDirectory($this->root);
        $owner = $this->root.'/owner';
        $path = $this->root.'/owner-transfer.json';
        $record = file_exists($path) || is_link($path) ? $this->read($path, 0, 0o644) : [];
        if ('active' !== ($record['phase'] ?? null)) {
            $this->intent();
            $metadata = lstat($owner);
            if (false === $metadata) {
                throw new \RuntimeException('Owner custody is unavailable.');
            }
            $record = ['format' => 'emailer-owner-transfer-v1', 'installation_uuid' => $this->installation, 'phase' => 'active', 'source_uid' => $metadata['uid'], 'target_uid' => $uid, 'target_gid' => $gid];
            $this->write($path, $record, 0, 0, 0o644);
        }
        if ('emailer-owner-transfer-v1' !== ($record['format'] ?? null) || $this->installation !== ($record['installation_uuid'] ?? null) || $uid !== ($record['target_uid'] ?? null) || $gid !== ($record['target_gid'] ?? null) || !\is_int($record['source_uid'] ?? null) || 0 >= $record['source_uid']) {
            throw new \RuntimeException('An interrupted custody transfer requires its recorded target.');
        }
        $metadata = lstat($owner);
        if (false === $metadata || 0o040000 !== ($metadata['mode'] & 0o170000) || 0 !== ($metadata['mode'] & 0o077) || !\in_array($metadata['uid'], [0, $record['source_uid'], $uid], true)) {
            throw new \RuntimeException('Owner transfer directory is unsafe.');
        }
        $lockPath = $owner.'/intent.lock';
        $lockMetadata = lstat($lockPath);
        if (false === $lockMetadata || !\in_array($lockMetadata['uid'], [$record['source_uid'], $uid], true)) {
            throw new \RuntimeException('Owner transfer lock custody is unsafe.');
        }
        $this->readLock($lockPath, $lockMetadata['uid']);
        $lock = $this->lockedStream($lockPath, $lockMetadata['uid'], LOCK_EX);
        try {
            // Remove directory mutation authority before privileged pathname
            // ownership changes. The root receipt makes partial transfer replayable.
            if (!chown($owner, 0) || !chgrp($owner, 0)) {
                throw new \RuntimeException('Owner custody could not be fenced.');
            }
            foreach ([$owner.'/intent.json', $lockPath] as $file) {
                $m = lstat($file);
                if (false === $m || !\in_array($m['uid'], [$record['source_uid'], $uid], true)) {
                    throw new \RuntimeException('Owner transfer file custody is unsafe.');
                }
                $this->readLock($file, $m['uid']);
                if (!chown($file, $uid) || !chgrp($file, $gid)) {
                    throw new \RuntimeException('Owner custody transfer failed.');
                }
                $stream = fopen($file, 'r');
                if (false === $stream) {
                    throw new \RuntimeException('Owner custody sync is unavailable.');
                }
                try {
                    if (!fsync($stream)) {
                        throw new \RuntimeException('Owner custody sync failed.');
                    }
                } finally {
                    fclose($stream);
                }
            }
            $this->syncDirectory($owner);
            if (!chown($owner, $uid) || !chgrp($owner, $gid)) {
                throw new \RuntimeException('Owner custody publication failed.');
            }
            $this->syncDirectory($owner);
            $this->syncDirectory($this->root);
            $record['phase'] = 'complete';
            $this->write($path, $record, 0, 0, 0o644);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function invalidateUnprovenLegacyIntent(): void
    {
        $this->requireRoot();
        $state = $this->status();
        if ('enabled' === $state['owner'] && 0 === $state['generation']) {
            $this->setIntent('unknown', 0);
        }
    }

    public function claimLease(): DeliveryIntentLease
    {
        $state = $this->status();
        if (!$state['configured']) {
            return new DeliveryIntentLease();
        }
        $owner = lstat($this->root.'/owner');
        if (false === $owner) {
            throw new \RuntimeException('Owner intent lease custody is unavailable.');
        }
        $path = $this->root.'/owner/intent.lock';
        $this->readLock($path, $owner['uid']);
        $stream = $this->lockedStream($path, $owner['uid'], LOCK_SH);
        $lease = new DeliveryIntentLease($stream);
        try {
            $state = $this->status();
            if ('enabled' !== $state['owner'] || [] !== $state['holds']) {
                throw new \DomainException('Delivery has an independent hold or owner pause.');
            }
        } catch (\Throwable $error) {
            $lease->release();
            throw $error;
        }

        return $lease;
    }

    public function pause(): void
    {
        $this->setIntent('paused');
    }

    /** Explicit owner Resume only; installer completion must not invoke this method. */
    public function enable(): void
    {
        if ([] !== $this->status()['holds']) {
            throw new \RuntimeException('Another operation still owns a delivery hold.');
        }
        $this->setIntent('enabled');
    }

    /** @param array<string, mixed> $evidence */
    public function hold(string $operation, string $kind, array $evidence): void
    {
        $this->requireRoot();
        $this->operation($operation);
        if (!\in_array($kind, ['host', 'native', 'edition', 'manual', 'recovery'], true)) {
            throw new \RuntimeException('Delivery hold ownership is invalid.');
        }
        $this->status();
        $file = $this->root.'/holds/'.$operation.'.json';
        if (file_exists($file) || is_link($file)) {
            $old = $this->read($file, 0, 0o644);
            $this->validateHold($old, $operation);
            if ($old['kind'] !== $kind || $old['evidence'] !== $evidence) {
                throw new \RuntimeException('Delivery hold operation evidence differs.');
            }

            return; // A released operation is terminal; never recreate its fence.
        }
        $this->write($file, ['format' => 'emailer-owned-hold-v1', 'installation_uuid' => $this->installation, 'operation_id' => $operation, 'kind' => $kind, 'phase' => 'active', 'evidence' => $evidence], 0, 0, 0o644);
    }

    /** @return array<string, mixed> */
    public function operationState(string $operation): array
    {
        $this->operation($operation);
        $this->rootDirectory($this->root.'/holds');
        $value = $this->read($this->root.'/holds/'.$operation.'.json', 0, 0o644);
        $this->validateHold($value, $operation);

        return $value;
    }

    public function projectionLease(): DeliveryIntentLease
    {
        $this->requireRoot();
        $this->intent();
        $owner = lstat($this->root.'/owner');
        if (false === $owner) {
            throw new \RuntimeException('Owner intent projection custody is unavailable.');
        }
        $path = $this->root.'/owner/intent.lock';
        $this->readLock($path, $owner['uid']);
        $stream = $this->lockedStream($path, $owner['uid'], LOCK_SH);

        return new DeliveryIntentLease($stream);
    }

    public function phase(string $operation, string $phase): void
    {
        $this->requireRoot();
        if (!\in_array($phase, ['active', 'handoff', 'failed', 'recovered', 'released'], true)) {
            throw new \RuntimeException('Delivery hold lifecycle is invalid.');
        }
        $this->operation($operation);
        $file = $this->root.'/holds/'.$operation.'.json';
        $old = $this->read($file, 0, 0o644);
        $this->validateHold($old, $operation);
        if ('released' === $old['phase']) {
            if ('released' !== $phase) {
                throw new \RuntimeException('A completed delivery hold cannot be reactivated.');
            }

            return;
        }
        $old['phase'] = $phase;
        $this->write($file, $old, 0, 0, 0o644);
    }

    /** @return array<string, mixed> */
    private function intent(): array
    {
        $this->rootDirectory($this->root);
        $owner = @lstat($this->root.'/owner');
        if (!\is_array($owner) || 0o040000 !== ($owner['mode'] & 0o170000) || 0 !== ($owner['mode'] & 0o077) || 0 >= $owner['uid']) {
            throw new \RuntimeException('Owner intent directory is unsafe.');
        }
        $intent = $this->read($this->root.'/owner/intent.json', $owner['uid'], 0o600);
        if ('emailer-owner-intent-v1' !== ($intent['format'] ?? null) || $this->installation !== ($intent['installation_uuid'] ?? null) || !\in_array($intent['owner'] ?? null, ['enabled', 'paused', 'unknown'], true) || !\is_int($intent['generation'] ?? null) || 0 > $intent['generation']) {
            throw new \RuntimeException('Owner intent evidence is invalid.');
        }

        return $intent;
    }

    private function setIntent(string $value, ?int $expectedGeneration = null): void
    {
        $before = $this->intent();
        $owner = lstat($this->root.'/owner');
        if (false === $owner || !\in_array(posix_geteuid(), [0, $owner['uid']], true)) {
            throw new \RuntimeException('Owner intent authority is invalid.');
        }
        $file = $this->root.'/owner/intent.lock';
        $this->readLock($file, $owner['uid']);
        $lock = $this->lockedStream($file, $owner['uid'], LOCK_EX);
        try {
            $before = $this->intent();
            if (null !== $expectedGeneration && $expectedGeneration !== $this->integer($before, 'generation')) {
                return; // Never replace a newer acknowledged pause or explicit Resume.
            }
            if (PHP_INT_MAX === $this->integer($before, 'generation')) {
                throw new \RuntimeException('Owner intent generation is exhausted.');
            }
            $before['owner'] = $value;
            $before['generation'] = $this->integer($before, 'generation') + 1;
            $this->writeOwnerIntent($before, $owner['uid'], $owner['gid']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string, mixed> $intent */
    private function writeOwnerIntent(array $intent, int $uid, int $gid): void
    {
        $root = 0 === posix_geteuid();
        $previousGroup = posix_getegid();
        if ($root) {
            if (!posix_setegid($gid)) {
                throw new \RuntimeException('Owner intent group context is unavailable.');
            }
            if (!posix_seteuid($uid)) {
                posix_setegid($previousGroup);
                throw new \RuntimeException('Owner intent user context is unavailable.');
            }
        }
        try {
            // No privileged pathname chmod/chown inside an app-writable
            // directory. A privileged caller writes with the owner's authority.
            $this->write($this->root.'/owner/intent.json', $intent, $uid, $gid, 0o600);
        } finally {
            if ($root && (!posix_seteuid(0) || !posix_setegid($previousGroup))) {
                throw new \RuntimeException('Privileged installer context could not be restored.');
            }
        }
    }

    private function bootstrapLock(string $owner, int $uid, int $gid): void
    {
        $this->requireRoot();
        $this->rootDirectory($owner);
        $path = $owner.'/intent.lock';
        if (!file_exists($path) && !is_link($path)) {
            $stream = fopen($path, 'x+b');
            if (false === $stream) {
                throw new \RuntimeException('Owner bootstrap lock could not be created.');
            }
            fclose($stream);
        }
        $m = lstat($path);
        if (false === $m || 0o100000 !== ($m['mode'] & 0o170000) || 1 !== $m['nlink'] || 0 !== $m['size'] || !\in_array($m['uid'], [0, $uid], true) || !chmod($path, 0o600) || !chown($path, $uid) || !chgrp($path, $gid)) {
            throw new \RuntimeException('Owner bootstrap lock custody is unsafe.');
        }
        $stream = fopen($path, 'r');
        if (false === $stream) {
            throw new \RuntimeException('Owner bootstrap lock sync is unavailable.');
        }
        try {
            if (!fsync($stream)) {
                throw new \RuntimeException('Owner bootstrap lock sync failed.');
            }
        } finally {
            fclose($stream);
        }
        $this->syncDirectory($owner);
    }

    /** @return resource */
    private function lockedStream(string $path, int $uid, int $mode): mixed
    {
        if (!\in_array($mode, [LOCK_SH, LOCK_EX], true)) {
            throw new \LogicException('Unsupported owner lock mode.');
        }
        $this->readLock($path, $uid);
        $before = lstat($path);
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Owner intent lock descriptor is unavailable.');
        }
        $opened = fstat($stream);
        if (false === $before || false === $opened || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino'] || 0o100000 !== ($opened['mode'] & 0o170000) || $uid !== $opened['uid'] || 0o600 !== ($opened['mode'] & 0o777) || 1 !== $opened['nlink'] || !flock($stream, $mode)) {
            fclose($stream);
            throw new \RuntimeException('Owner intent lock descriptor custody is unsafe.');
        }

        return $stream;
    }

    private function readLock(string $path, int $uid): void
    {
        clearstatcache(true, $path);
        $m = @lstat($path);
        if (!\is_array($m) || 0o100000 !== ($m['mode'] & 0o170000) || $uid !== $m['uid'] || 0o600 !== ($m['mode'] & 0o777) || 1 !== $m['nlink']) {
            throw new \RuntimeException('Owner intent lock metadata is unsafe.');
        }
    }

    /** @return array<string, mixed> */
    private function read(string $path, int $uid, int $mode): array
    {
        clearstatcache(true, $path);
        $m = @lstat($path);
        if (!\is_array($m) || 0o100000 !== ($m['mode'] & 0o170000) || $uid !== $m['uid'] || ($m['mode'] & 0o777) !== $mode || 1 !== $m['nlink'] || 16384 < $m['size']) {
            throw new \RuntimeException('Delivery control record is unsafe.');
        }
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Delivery control descriptor is unavailable.');
        }
        try {
            $opened = fstat($stream);
            if (false === $opened || 0o100000 !== ($opened['mode'] & 0o170000) || $uid !== $opened['uid'] || ($opened['mode'] & 0o777) !== $mode || 1 !== $opened['nlink'] || 16384 < $opened['size']) {
                throw new \RuntimeException('Delivery control descriptor custody is unsafe.');
            }
            $bytes = stream_get_contents($stream, 16385);
            if (false === $bytes || 16384 < \strlen($bytes)) {
                throw new \RuntimeException('Delivery control descriptor exceeds its bound.');
            }
        } finally {
            fclose($stream);
        }
        $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($value)) {
            throw new \RuntimeException('Delivery control record is invalid.');
        }

        $record = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Delivery control record keys are invalid.');
            }
            $record[$key] = $item;
        }

        return $record;
    }

    /** @param array<string, mixed> $value */
    private function write(string $path, array $value, int $uid, int $gid, int $mode): void
    {
        $bytes = json_encode($value, JSON_THROW_ON_ERROR)."\n";
        if (16384 < \strlen($bytes)) {
            throw new \RuntimeException('Delivery control record exceeds its safe bound.');
        }
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'x+b');
        if (false === $stream) {
            throw new \RuntimeException('Delivery control record could not be staged.');
        }
        try {
            if (!chmod($temporary, $mode) || (0 === posix_geteuid() && (!chown($temporary, $uid) || !chgrp($temporary, $gid))) || \strlen($bytes) !== fwrite($stream, $bytes) || !fflush($stream) || !fsync($stream) || !rename($temporary, $path)) {
                throw new \RuntimeException('Delivery control record could not be published.');
            }
            $directory = fopen(\dirname($path), 'r');
            if (false === $directory) {
                throw new \RuntimeException('Delivery control directory could not be synchronized.');
            }
            try {
                if (!fsync($directory)) {
                    throw new \RuntimeException('Delivery control directory sync failed.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            fclose($stream);
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** An absent namespace is legacy only when absence can actually be examined. */
    private function namespaceAvailable(): bool
    {
        $base = \dirname($this->root);
        if (!$this->entryExists(\dirname($base), basename($base))) {
            return false;
        }
        $this->publicControlDirectory($base);
        if (!$this->entryExists($base, $this->installation)) {
            return false;
        }
        $this->publicControlDirectory($this->root);
        $this->publicControlDirectory($this->root.'/holds');

        return true;
    }

    private function entryExists(string $directory, string $name): bool
    {
        $m = @lstat($directory);
        if (!\is_array($m) || 0o040000 !== ($m['mode'] & 0o170000) || !is_readable($directory) || !is_executable($directory)) {
            throw new \RuntimeException('Control namespace cannot be examined.');
        }
        $stream = @opendir($directory);
        if (false === $stream) {
            throw new \RuntimeException('Control namespace inventory is unreadable.');
        }
        try {
            for ($count = 0; false !== ($entry = readdir($stream)); ++$count) {
                if (4096 <= $count) {
                    throw new \RuntimeException('Control namespace inventory exceeds its bound.');
                }
                if ($entry === $name) {
                    return true;
                }
            }

            return false;
        } finally {
            closedir($stream);
        }
    }

    private function publicControlDirectory(string $path): void
    {
        $this->rootDirectory($path);
        $m = lstat($path);
        if (false === $m || 0 !== $m['gid'] || 0o755 !== ($m['mode'] & 0o777)) {
            throw new \RuntimeException('Control namespace traversal is invalid.');
        }
        for ($p = \dirname($path);; $p = \dirname($p)) {
            $m = lstat($p);
            if (false === $m || 0 === ($m['mode'] & 0o001)) {
                throw new \RuntimeException('Control namespace ancestor traversal is invalid.');
            }
            if ('/' === $p) {
                break;
            }
        }
    }

    /** Repair only protected product-owned directories, never arbitrary ancestors. */
    private function prepareControlDirectory(string $path): void
    {
        $this->rootDirectory(\dirname($path));
        if (!$this->entryExists(\dirname($path), basename($path))) {
            if (!mkdir($path, 0o700)) {
                throw new \RuntimeException('Control namespace could not be created.');
            }
            $this->syncDirectory(\dirname($path));
        }
        $this->rootDirectory($path);
        $before = lstat($path);
        $stream = fopen($path, 'r');
        if (false === $before || false === $stream) {
            throw new \RuntimeException('Control namespace descriptor is unavailable.');
        }
        try {
            $opened = fstat($stream);
            if (false === $opened || $before['dev'] !== $opened['dev'] || $before['ino'] !== $opened['ino'] || 0o040000 !== ($opened['mode'] & 0o170000) || 0 !== $opened['uid'] || 0 !== ($opened['mode'] & 0o022) || !chgrp($path, 0) || !chmod($path, 0o755)) {
                throw new \RuntimeException('Control namespace publication failed.');
            }
            clearstatcache(true, $path);
            $after = lstat($path);
            if (false === $after || $opened['dev'] !== $after['dev'] || $opened['ino'] !== $after['ino'] || 0 !== $after['uid'] || 0 !== $after['gid'] || 0o755 !== ($after['mode'] & 0o777) || !fsync($stream)) {
                throw new \RuntimeException('Control namespace publication is unverified.');
            }
        } finally {
            fclose($stream);
        }
        $this->publicControlDirectory($path);
    }

    /** @return array<string, mixed> */
    private function beginAccessRepair(bool $unknownPrior): array
    {
        $base = \dirname($this->root);
        $needed = false;
        foreach ([$base, $this->root, $this->root.'/holds'] as $path) {
            if (\is_array($m = @lstat($path))) {
                $this->rootDirectory($path);
                $needed = $needed || 0 !== $m['gid'] || 0o755 !== ($m['mode'] & 0o777);
            }
        }
        if (!\is_array(@lstat($this->root.'/owner/intent.json'))) {
            return []; // Fresh/bootstrap state has no previously acknowledged intent.
        }
        $this->intent();
        foreach ([$base, $this->root, $this->root.'/holds'] as $path) {
            if (!\is_array(@lstat($path))) {
                throw new \RuntimeException('Established delivery namespace is incomplete.');
            }
        }
        $path = $this->root.'/access-repair.json';
        $record = [];
        if (\is_array(@lstat($path))) {
            $record = $this->read($path, 0, 0o644);
            if ('emailer-control-access-repair-v1' !== ($record['format'] ?? null) || $this->installation !== ($record['installation_uuid'] ?? null) || !\is_bool($record['unknown_prior'] ?? null) || !\in_array($record['phase'] ?? null, ['active', 'complete'], true)) {
                throw new \RuntimeException('Control access repair binding is invalid.');
            }
        }
        if (!$needed && 'active' !== ($record['phase'] ?? null)) {
            return [];
        }
        // Publish before chmod, so interruption cannot lose an ambiguous DB-only Pause.
        $record = ['format' => 'emailer-control-access-repair-v1', 'installation_uuid' => $this->installation, 'phase' => 'active', 'unknown_prior' => $unknownPrior || true === ($record['unknown_prior'] ?? null)];
        $this->write($path, $record, 0, 0, 0o644);

        return $record;
    }

    private function rootDirectory(string $path): void
    {
        for ($p = $path;; $p = \dirname($p)) {
            clearstatcache(true, $p);
            $m = @lstat($p);
            if (!\is_array($m) || 0o040000 !== ($m['mode'] & 0o170000) || 0 !== $m['uid'] || 0 !== ($m['mode'] & 0o022)) {
                throw new \RuntimeException('Delivery control directory ownership is unsafe.');
            }
            if ('/' === $p) {
                return;
            }
        }
    }

    /** @param array<string, mixed> $hold */
    private function validateHold(array $hold, string $operation): void
    {
        $this->operation($operation);
        if ('emailer-owned-hold-v1' !== ($hold['format'] ?? null) || $this->installation !== ($hold['installation_uuid'] ?? null) || $operation !== ($hold['operation_id'] ?? null) || !\in_array($hold['kind'] ?? null, ['host', 'native', 'edition', 'manual', 'recovery'], true) || !\in_array($hold['phase'] ?? null, ['active', 'handoff', 'failed', 'recovered', 'released'], true) || !\is_array($hold['evidence'] ?? null)) {
            throw new \RuntimeException('Delivery hold operation binding is invalid.');
        }
    }

    private function operation(string $operation): void
    {
        if (1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $operation)) {
            throw new \RuntimeException('Delivery hold operation identity is invalid.');
        }
    }

    private function syncDirectory(string $path): void
    {
        $stream = fopen($path, 'r');
        if (false === $stream) {
            throw new \RuntimeException('Delivery control directory sync is unavailable.');
        }
        try {
            if (!fsync($stream)) {
                throw new \RuntimeException('Delivery control directory sync failed.');
            }
        } finally {
            fclose($stream);
        }
    }

    private function requireRoot(): void
    {
        if (0 !== posix_geteuid()) {
            throw new \RuntimeException('Only the verified privileged installer may change operation holds.');
        }
    }

    /** @param array<string, mixed> $value */
    private function text(array $value, string $key): string
    {
        if (!\is_string($value[$key] ?? null)) {
            throw new \RuntimeException('Delivery control text is invalid.');
        }

        return $value[$key];
    }

    /** @param array<string, mixed> $value */
    private function integer(array $value, string $key): int
    {
        if (!\is_int($value[$key] ?? null)) {
            throw new \RuntimeException('Delivery control generation is invalid.');
        }

        return $value[$key];
    }
}
