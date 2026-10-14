<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Setup\Application\Contract\InstallationLock;
use Symfony\Component\HttpFoundation\Session\Storage\Handler\NativeFileSessionHandler;

final class HybridSessionHandler implements \SessionHandlerInterface, \SessionUpdateTimestampHandlerInterface
{
    private \SessionHandlerInterface $active;

    public function __construct(
        private readonly InstallationLock $installationLock,
        private readonly DatabaseSessionHandler $database,
        string $setupSessionDirectory,
    ) {
        if (is_link($setupSessionDirectory)) {
            throw new \RuntimeException('The setup session directory must not be a symbolic link.');
        }
        if (!is_dir($setupSessionDirectory) && !@mkdir($setupSessionDirectory, 0700, true) && !is_dir($setupSessionDirectory)) {
            throw new \RuntimeException('The setup session directory could not be created.');
        }
        if (!@chmod($setupSessionDirectory, 0700)) {
            throw new \RuntimeException('The setup session directory permissions could not be secured.');
        }
        clearstatcache(true, $setupSessionDirectory);
        $permissions = fileperms($setupSessionDirectory);
        if (false === $permissions || 0700 !== ($permissions & 0777)) {
            throw new \RuntimeException('The setup session directory permissions are unsafe.');
        }
        $this->active = new NativeFileSessionHandler($setupSessionDirectory);
    }

    public function open(string $path, string $name): bool
    {
        if ($this->installationLock->exists()) {
            $this->active = $this->database;
        }

        return $this->active->open($path, $name);
    }

    public function close(): bool
    {
        return $this->active->close();
    }

    public function read(string $id): string|false
    {
        return $this->active->read($id);
    }

    public function write(string $id, string $data): bool
    {
        return $this->active->write($id, $data);
    }

    public function destroy(string $id): bool
    {
        return $this->active->destroy($id);
    }

    public function gc(int $max_lifetime): int|false
    {
        return $this->active->gc($max_lifetime);
    }

    public function validateId(string $id): bool
    {
        return $this->active instanceof \SessionUpdateTimestampHandlerInterface
            ? $this->active->validateId($id)
            : true;
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        return $this->active instanceof \SessionUpdateTimestampHandlerInterface
            ? $this->active->updateTimestamp($id, $data)
            : $this->active->write($id, $data);
    }
}
