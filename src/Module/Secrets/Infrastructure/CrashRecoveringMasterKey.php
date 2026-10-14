<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\TransactionalMasterKey;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final class CrashRecoveringMasterKey implements TransactionalMasterKey
{
    private ?string $activeRotation = null;

    public function __construct(private readonly FilesystemMasterKey $filesystem, private readonly Connection $connection, private readonly MaintenanceOperationLock $maintenance)
    {
    }

    public function create(): void
    {
        $this->reconcile();
        $this->filesystem->create();
    }

    public function load(): string
    {
        $this->reconcile();

        return $this->filesystem->load();
    }

    public function exists(): bool
    {
        $this->reconcile();

        return $this->filesystem->exists();
    }

    public function recover(string $key): void
    {
        $this->reconcile();
        $this->filesystem->recover($key);
    }

    public function replace(string $expectedCurrentKey, string $replacementKey): void
    {
        $this->reconcile();
        $this->filesystem->replace($expectedCurrentKey, $replacementKey);
    }

    public function beginRotation(string $expectedCurrentKey, string $replacementKey, string $operationId): void
    {
        $this->reconcile();
        $this->filesystem->prepareRotation($expectedCurrentKey, $replacementKey, $operationId);
        $this->activeRotation = $operationId;
    }

    public function publishRotation(string $operationId): void
    {
        if ($operationId !== $this->activeRotation) {
            throw new \RuntimeException('Master-key rotation journal is not owned by this operation.');
        }
        $this->filesystem->publishRotation($operationId);
    }

    public function resolveRotation(string $operationId): void
    {
        $pending = $this->filesystem->pendingRotationId();
        if (null === $pending) {
            $this->activeRotation = null;

            return;
        }
        if (!hash_equals($pending, $operationId)) {
            throw new \RuntimeException('A different master-key rotation requires recovery.');
        }
        $committedMarker = $this->connection->fetchOne(
            "SELECT 1 FROM audit_event WHERE action = 'secrets.master_key.rotated' AND target_id = ? AND result = 'success' LIMIT 1",
            [$operationId],
        );
        $committed = is_numeric($committedMarker) && 1 === (int) $committedMarker;
        $this->filesystem->resolveRotation($operationId, $committed);
        $this->activeRotation = null;
    }

    private function reconcile(): void
    {
        if (null !== $this->activeRotation) {
            return;
        }
        $pending = $this->filesystem->pendingRotationId();
        if (null === $pending) {
            return;
        }
        if (!$this->maintenance->tryAcquire()) {
            throw new \RuntimeException('Master-key rotation is active; key access is temporarily unavailable.');
        }
        try {
            $pending = $this->filesystem->pendingRotationId();
            if (null !== $pending) {
                $this->resolveRotation($pending);
            }
        } finally {
            $this->maintenance->release();
        }
    }
}
