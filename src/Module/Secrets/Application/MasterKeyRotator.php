<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Secrets\Application\Contract\RecoveryKeyVerifier;
use App\Module\Secrets\Application\Contract\TransactionalMasterKey;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

final readonly class MasterKeyRotator
{
    public function __construct(
        private Connection $connection,
        private TransactionalMasterKey $masterKey,
        private RecoveryKeyVerifier $recoveryKeyVerifier,
        private SecretCipher $cipher,
        private BackupOperations $backups,
        private MaintenanceOperationLock $maintenance,
        private AuditSink $audit,
    ) {
    }

    /** @return array{backup_id: string, rotated_secret_count: int} */
    public function rotate(string $actorUserId, string $recoveryPassphrase): array
    {
        $this->maintenance->acquire();
        try {
            $this->assertDeliveryQuiesced(false);
            $oldKey = $this->masterKey->load();
            try {
                if (!$this->recoveryKeyVerifier->verifiesKey($recoveryPassphrase, $oldKey)) {
                    throw new \RuntimeException('The current recovery-key package could not be authenticated.');
                }
                $backup = $this->backups->create();
                $newKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
                $operationId = Identity::uuidV7();
                $rotationPrepared = false;
                $transactionStarted = false;

                try {
                    $this->connection->beginTransaction();
                    $transactionStarted = true;
                    $installationId = $this->connection->fetchOne(
                        "SELECT installation_uuid FROM installation_state WHERE singleton_key = '1' FOR UPDATE",
                    );
                    if (!\is_string($installationId) || '' === $installationId) {
                        throw new \RuntimeException('Installation identity is unavailable.');
                    }
                    $this->assertDeliveryQuiesced(true);

                    $records = $this->connection->fetchAllAssociative(
                        'SELECT id, record_type, envelope, key_version, schema_version FROM encrypted_secret ORDER BY id FOR UPDATE',
                    );
                    foreach ($records as $record) {
                        $id = $record['id'] ?? null;
                        $recordType = $record['record_type'] ?? null;
                        $envelope = $record['envelope'] ?? null;
                        if (!\is_string($id)
                            || !\is_string($recordType)
                            || !\is_string($envelope)
                            || !is_numeric($record['key_version'] ?? null)
                            || !is_numeric($record['schema_version'] ?? null)
                            || SecretCipher::KEY_VERSION !== (int) $record['key_version']
                            || SecretContext::SCHEMA_VERSION !== (int) $record['schema_version']
                        ) {
                            throw new \RuntimeException('Encrypted secret inventory is incompatible with rotation.');
                        }
                        $context = new SecretContext($installationId, $recordType, $id, (int) $record['schema_version']);
                        $plaintext = $this->cipher->decrypt($envelope, $oldKey, $context);
                        try {
                            $replacement = $this->cipher->encrypt($plaintext, $newKey, $context);
                        } finally {
                            sodium_memzero($plaintext);
                        }
                        if (1 !== $this->connection->update('encrypted_secret', [
                            'envelope' => $replacement,
                            'rotated_at' => Identity::now(),
                        ], ['id' => $id])) {
                            throw new \RuntimeException('Encrypted secret rotation failed.');
                        }
                    }

                    $this->masterKey->beginRotation($oldKey, $newKey, $operationId);
                    $rotationPrepared = true;
                    $this->masterKey->publishRotation($operationId);
                    $this->audit->append(new AuditRecord(
                        $actorUserId,
                        'secrets.master_key.rotated',
                        $operationId,
                        'success',
                        ['encrypted_record_count' => \count($records), 'backup_id' => $backup['id']],
                    ));
                    $this->connection->commit();
                    $transactionStarted = false;
                    $this->masterKey->resolveRotation($operationId);

                    return ['backup_id' => $backup['id'], 'rotated_secret_count' => \count($records)];
                } catch (\Throwable $exception) {
                    if ($transactionStarted && $this->connection->isTransactionActive()) {
                        $this->connection->rollBack();
                    }
                    if ($rotationPrepared) {
                        try {
                            $this->masterKey->resolveRotation($operationId);
                        } catch (\Throwable $recoveryFailure) {
                            throw new \RuntimeException('Master-key rotation failed and its durable journal could not be reconciled. Keep delivery stopped; the next application start must reconcile the journal before secrets are used.', previous: $recoveryFailure);
                        }
                    }
                    throw $exception;
                } finally {
                    sodium_memzero($newKey);
                }
            } finally {
                sodium_memzero($oldKey);
            }
        } finally {
            $this->maintenance->release();
        }
    }

    private function assertDeliveryQuiesced(bool $lock): void
    {
        $deliveryState = $this->connection->fetchAssociative(
            "SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'".($lock ? ' FOR UPDATE' : ''),
        );
        if (false === $deliveryState
            || !is_numeric($deliveryState['accepting_work'] ?? null)
            || !is_numeric($deliveryState['active_claims'] ?? null)
            || 0 !== (int) $deliveryState['accepting_work']
            || 0 !== (int) $deliveryState['active_claims']
        ) {
            throw new \RuntimeException('Delivery must be quiesced before master-key rotation.');
        }
    }
}
