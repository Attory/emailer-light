<?php

declare(strict_types=1);

namespace App\Module\Backup\Infrastructure;

use App\Module\Backup\Application\Contract\AutomatedBackupOperations;
use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use Doctrine\DBAL\Connection;

final readonly class BackupReadinessProbe implements LightweightReadinessProbe
{
    public function __construct(private Connection $connection, private AutomatedBackupOperations $automation, private OperationsPolicyReader $policy, private string $backupDirectory, private string $recoveryKeyFile, private string $environment)
    {
    }

    public function findings(): array
    {
        if ('test' === $this->environment) {
            return [];
        }
        $findings = [];
        $recent = $this->connection->fetchOne("SELECT 1 FROM backup_record WHERE status = 'verified' AND verified_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 24 HOUR) LIMIT 1");
        if (false === $recent) {
            $findings[] = ['code' => 'backup_recency', 'severity' => 'critical', 'summary' => 'No verified backup exists from the last 24 hours.', 'remediation' => 'Create and verify an encrypted backup.'];
        }
        if (!$this->safeRecoveryPackage()) {
            $findings[] = ['code' => 'recovery_key_package', 'severity' => 'critical', 'summary' => 'A separate recovery-key package is not configured.', 'remediation' => 'Export the passphrase-encrypted recovery-key package and store it separately.'];
        }
        if (!is_dir($this->backupDirectory) || !is_writable($this->backupDirectory)) {
            $findings[] = ['code' => 'backup_storage', 'severity' => 'critical', 'summary' => 'Private backup storage is unavailable.', 'remediation' => 'Provide writable restrictive backup storage outside the release and public roots.'];
        }
        $policy = $this->policy->current();
        if (1 === $policy['backup_automation_enabled']) {
            if (!$this->automation->destinationAvailable((string) $policy['backup_destination_type'])) {
                $findings[] = ['code' => 'backup_destination', 'severity' => 'critical', 'summary' => 'The approved external backup destination is unavailable.', 'remediation' => 'Restore the provisioned destination or disable automation after preserving recovery coverage.'];
            }
            $lastTransfer = $this->connection->fetchOne("SELECT completed_at FROM backup_transfer WHERE status = 'succeeded' ORDER BY completed_at DESC LIMIT 1");
            $transferCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $policy['backup_interval_hours'].' hours');
            if (!\is_string($lastTransfer) || new \DateTimeImmutable($lastTransfer, new \DateTimeZone('UTC')) < $transferCutoff) {
                $findings[] = ['code' => 'backup_external_recency', 'severity' => 'critical', 'summary' => 'No recent verified external backup transfer is recorded.', 'remediation' => 'Run the operations scheduler and verify the approved destination.'];
            }
        }
        $lastRestore = $this->connection->fetchOne('SELECT MAX(restored_at) FROM backup_record');
        $restoreCutoff = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-'.(int) $policy['restore_drill_interval_days'].' days');
        if (!\is_string($lastRestore) || new \DateTimeImmutable($lastRestore, new \DateTimeZone('UTC')) < $restoreCutoff) {
            $findings[] = ['code' => 'restore_drill_overdue', 'severity' => 'warning', 'summary' => 'No restore verification is recorded within the configured cadence.', 'remediation' => 'Perform a supervised restore drill in an isolated environment and preserve the evidence.'];
        }

        return $findings;
    }

    private function safeRecoveryPackage(): bool
    {
        $stat = @lstat($this->recoveryKeyFile);
        if ('' === $this->recoveryKeyFile || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077) || $stat['size'] < 1 || $stat['size'] > 4096) {
            return false;
        }
        try {
            $contents = file_get_contents($this->recoveryKeyFile);
            $envelope = false === $contents ? null : json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

            if (!\is_array($envelope)
                || 'emailer-light-recovery-key-v1' !== ($envelope['format'] ?? null)
                || 'argon2id13' !== ($envelope['kdf'] ?? null)
                || 3 !== ($envelope['opslimit'] ?? null)
                || 67108864 !== ($envelope['memlimit'] ?? null)
                || !\is_string($envelope['salt'] ?? null)
                || !\is_string($envelope['nonce'] ?? null)
                || !\is_string($envelope['ciphertext'] ?? null)) {
                return false;
            }
            $salt = sodium_base642bin($envelope['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $nonce = sodium_base642bin($envelope['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $ciphertext = sodium_base642bin($envelope['ciphertext'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');

            return SODIUM_CRYPTO_PWHASH_SALTBYTES === \strlen($salt)
                && SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES === \strlen($nonce)
                && 48 === \strlen($ciphertext);
        } catch (\Throwable) {
            return false;
        }
    }
}
