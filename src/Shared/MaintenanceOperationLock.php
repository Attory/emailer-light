<?php

declare(strict_types=1);

namespace App\Shared;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

final class MaintenanceOperationLock
{
    private int $depth = 0;
    private ?LockInterface $lock = null;
    /** @var resource|null Root-only inherited open-file description, never explicitly unlocked here. */
    private mixed $inherited = null;

    public function __construct(private readonly LockFactory $locks)
    {
    }

    public function acquire(): void
    {
        if (!$this->tryAcquire()) {
            throw new \RuntimeException('Another installation-wide maintenance operation is active.');
        }
    }

    public function tryAcquire(): bool
    {
        if (0 < $this->depth) {
            ++$this->depth;

            return true;
        }
        if ($this->adoptInstallerLease()) {
            $this->depth = 1;

            return true;
        }
        $lock = $this->locks->createLock('emailer-maintenance-operation', 7200.0);
        if (!$lock->acquire()) {
            return false;
        }
        $this->lock = $lock;
        $this->depth = 1;

        return true;
    }

    private function adoptInstallerLease(): bool
    {
        $descriptor = getenv('EMAILER_INSTALLER_MAINTENANCE_FD');
        if (false === $descriptor || '' === $descriptor) {
            return false;
        }
        $setting = getenv('LOCK_DSN');
        if (0 !== posix_geteuid() || 1 !== preg_match('/^[0-9]{1,6}$/D', $descriptor) || 3 > (int) $descriptor || !\is_string($setting) || !str_starts_with($setting, 'flock:///')) {
            throw new \RuntimeException('Installer maintenance lease context is invalid.');
        }
        $key = 'emailer-maintenance-operation';
        $suffix = str_replace('/', '_', substr(base64_encode(hash('sha256', $key, true)), 0, 7));
        $path = substr($setting, 8).'/sf.'.$key.'.'.$suffix.'.lock';
        $expected = lstat($path);
        $stream = \is_resource($this->inherited) ? $this->inherited : fopen('php://fd/'.$descriptor, 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Installer maintenance descriptor is unavailable.');
        }
        $observed = fstat($stream);
        if (false === $expected || false === $observed || 0o100000 !== ($expected['mode'] & 0o170000) || 1 !== $expected['nlink'] || $expected['dev'] !== $observed['dev'] || $expected['ino'] !== $observed['ino'] || !flock($stream, LOCK_EX | LOCK_NB)) {
            fclose($stream);
            throw new \RuntimeException('Installer maintenance descriptor does not hold this installation lock.');
        }
        $this->inherited = $stream;

        return true;
    }

    public function release(): void
    {
        if (1 > $this->depth) {
            throw new \LogicException('Maintenance lock release is unbalanced.');
        }
        --$this->depth;
        if (0 === $this->depth) {
            $this->lock?->release();
            $this->lock = null;
        }
    }
}
