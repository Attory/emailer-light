<?php

declare(strict_types=1);

namespace App\Module\Configuration\Application;

/** Binds successful AUTH to exactly the configuration subsequently saved. */
final readonly class MailConnectionEvidence
{
    public function __construct(#[\SensitiveParameter] private string $applicationSecret, private string $stateDirectory)
    {
    }

    /** A failed newer attempt invalidates any stale session copy of a success. */
    public function begin(string $actorId): string
    {
        if (!is_dir($this->stateDirectory) && !mkdir($this->stateDirectory, 0o700, true) && !is_dir($this->stateDirectory)) {
            throw new \RuntimeException('Connection-test evidence storage is unavailable.');
        }
        $this->assertDirectory();
        $existing = @lstat($this->path($actorId));
        if (false !== $existing && (0o100000 !== ($existing['mode'] & 0o170000) || 1 !== $existing['nlink'] || posix_geteuid() !== $existing['uid'] || 0 !== ($existing['mode'] & 0o077) || 64 !== $existing['size'])) {
            throw new \RuntimeException('Connection-test evidence is not a private regular file.');
        }
        $attempt = bin2hex(random_bytes(32));
        $temporary = $this->path($actorId).'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'xb');
        if (false === $handle) {
            throw new \RuntimeException('Connection-test evidence could not be recorded.');
        }
        try {
            if (!chmod($temporary, 0o600) || 64 !== fwrite($handle, $attempt) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Connection-test evidence could not be recorded.');
            }
            fclose($handle);
            $handle = null;
            if (!rename($temporary, $this->path($actorId))) {
                throw new \RuntimeException('Connection-test evidence could not be recorded.');
            }
            $directory = fopen($this->stateDirectory, 'rb');
            if (false === $directory) {
                throw new \RuntimeException('Connection-test evidence could not be synchronized.');
            }
            try {
                if (!fsync($directory)) {
                    throw new \RuntimeException('Connection-test evidence could not be synchronized.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (\is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $attempt;
    }

    private function path(string $actorId): string
    {
        return $this->stateDirectory.'/'.hash('sha256', $actorId).'.attempt';
    }

    private function assertDirectory(): void
    {
        $stat = lstat($this->stateDirectory);
        if (false === $stat || is_link($this->stateDirectory) || 0o040000 !== ($stat['mode'] & 0o170000) || posix_geteuid() !== $stat['uid'] || 0 !== ($stat['mode'] & 0o077)) {
            throw new \RuntimeException('Connection-test evidence storage is not private.');
        }
    }

    private function isCurrent(MailConnectionProof $proof): bool
    {
        if (!is_dir($this->stateDirectory)) {
            return false;
        }
        $this->assertDirectory();
        $path = $this->path($proof->actorId);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (false === $stat || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || posix_geteuid() !== $stat['uid'] || 0 !== ($stat['mode'] & 0o077) || 64 !== $stat['size']) {
            return false;
        }
        $attempt = file_get_contents($path);

        return \is_string($attempt) && hash_equals($attempt, $proof->attempt);
    }

    public function fingerprint(string $actorId, string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password): string
    {
        $key = hash_hmac('sha256', 'emailer.smtp-form-test.v1', $this->applicationSecret, true);
        try {
            return hash_hmac('sha256', json_encode([$actorId, $host, $port, $encryption, $username, $password], JSON_THROW_ON_ERROR), $key);
        } finally {
            sodium_memzero($key);
        }
    }

    public function matches(?MailConnectionProof $proof, string $actorId, string $host, int $port, string $encryption, string $username, #[\SensitiveParameter] string $password): bool
    {
        return null !== $proof && $this->isCurrent($proof) && $proof->actorId === $actorId && $proof->expiresAt >= time() && $proof->expiresAt <= time() + 600 && hash_equals($proof->fingerprint, $this->fingerprint($actorId, $host, $port, $encryption, $username, $password));
    }
}
