<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\InstallationLock;

final readonly class FilesystemInstallationLock implements InstallationLock
{
    public function __construct(private string $lockFile)
    {
    }

    public function exists(): bool
    {
        return file_exists($this->lockFile) || is_link($this->lockFile);
    }

    public function create(array $metadata): void
    {
        if ($this->exists()) {
            throw new \RuntimeException('Installation lock already exists.');
        }

        $directory = \dirname($this->lockFile);
        FilesystemChallengeStore::ensurePrivateDirectory($directory);
        $contents = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $temporaryFile = $directory.'/.install-lock-'.bin2hex(random_bytes(8)).'.tmp';

        try {
            if (false === @file_put_contents($temporaryFile, $contents, LOCK_EX) || !@chmod($temporaryFile, 0o600)) {
                throw new \RuntimeException('Unable to create installation lock safely.');
            }

            if (!@link($temporaryFile, $this->lockFile)) {
                throw new \RuntimeException('Installation lock already exists or cannot be published safely.');
            }
        } finally {
            @unlink($temporaryFile);
        }
    }

    public function remove(): void
    {
        if (!$this->exists()) {
            return;
        }

        $metadata = @lstat($this->lockFile);

        if (false === $metadata) {
            throw new \RuntimeException('Installation lock changed during validation.');
        }

        if ('file' !== @filetype($this->lockFile) || 0 !== ($metadata['mode'] & 0o022)) {
            throw new \RuntimeException('Installation lock has unsafe filesystem metadata.');
        }

        if (!@unlink($this->lockFile)) {
            throw new \RuntimeException('Unable to remove installation lock.');
        }
    }
}
