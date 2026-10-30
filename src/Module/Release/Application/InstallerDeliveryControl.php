<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Shared\DeliveryControlFiles;
use Doctrine\DBAL\Connection;

/** Privileged completion bridge. Never clears independent owner intent. */
final readonly class InstallerDeliveryControl
{
    private DeliveryControlFiles $files;
    private string $installation;

    public function __construct(private Connection $connection, private string $privateDirectory)
    {
        if (0 !== posix_geteuid()) {
            throw new \RuntimeException('Installer delivery completion requires the protected privileged context.');
        }
        $id = $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (!\is_string($id)) {
            throw new \RuntimeException('Installer delivery identity is unavailable.');
        }
        $this->installation = $id;
        $this->files = new DeliveryControlFiles($privateDirectory, $id);
    }

    public function beginFresh(string $operation, string $proof, int $uid, int $gid): void
    {
        $record = $this->rootProof($proof);
        if ($operation !== ($record['control_operation_id'] ?? null) || $this->installation !== ($record['control_installation_uuid'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || !\is_array($record['completed'] ?? null) || !\in_array('Creating your administrator account', $record['completed'], true)) {
            throw new \RuntimeException('Fresh installation delivery binding is invalid.');
        }
        $this->files->prepare($uid, $gid, 'enabled');
        $this->files->hold($operation, 'host', ['proof_path' => $proof, 'fresh' => true]);
        if ('released' !== $this->files->operationState($operation)['phase']) {
            $this->connection->transactional(function (): void {
                $active = $this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
                if (!is_numeric($active) || 0 !== (int) $active) {
                    throw new \RuntimeException('Fresh installation has unexpected active delivery claims.');
                }
                $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            });
        }
    }

    public function completeFresh(string $operation, string $proof): void
    {
        $record = $this->rootProof($proof);
        $hold = $this->files->operationState($operation);
        if ($operation !== ($record['control_operation_id'] ?? null) || $this->installation !== ($record['control_installation_uuid'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || true !== ($record['control_runtime_verified'] ?? null) || !\is_array($record['completed'] ?? null) || !\in_array('Checking application health and initial backup', $record['completed'], true) || 'host' !== $hold['kind'] || ['proof_path' => $proof, 'fresh' => true] !== $hold['evidence']) {
            throw new \RuntimeException('Fresh installation runtime completion is not verified.');
        }
        $lease = $this->files->projectionLease();
        try {
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    public function beginHost(string $operation, string $proof, int $uid, int $gid, ?string $invocation = null, bool $legacyAccessBlocked = false): void
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || true !== ($record['delivery_source_verified'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null)) {
            throw new \RuntimeException('Installer delivery source binding is invalid.');
        }
        $prior = $this->connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'");
        if ((!\is_string($prior) && !\is_int($prior)) || !\in_array((string) $prior, ['0', '1'], true)) {
            throw new \RuntimeException('Earlier delivery intent is unavailable.');
        }
        $this->files->prepare($uid, $gid, '1' === (string) $prior ? 'enabled' : 'unknown');
        foreach ($this->files->status()['holds'] as $other) {
            $evidence = $other['evidence'];
            if ($operation !== ($other['operation_id'] ?? null) && \in_array($other['kind'] ?? null, ['host', 'native', 'edition'], true) && !('native' === $other['kind'] && \is_array($evidence) && $operation === ($evidence['parent_operation'] ?? null))) {
                throw new \RuntimeException('A separate installer operation requires its original Finish or Recover flow before another host operation. Independent holds were preserved.');
            }
        }
        $this->files->hold($operation, 'host', ['proof_path' => $proof]);
        if ('released' !== $this->files->operationState($operation)['phase'] && true === ($record['control_legacy_source'] ?? null) && $invocation !== ($record['control_capture_invocation'] ?? null) && !$legacyAccessBlocked) {
            $this->files->invalidateUnprovenLegacyIntent();
        }
        if ('1' === ($record['control_requested_action'] ?? null) && 'recovered' === $this->files->operationState($operation)['phase']) {
            $this->files->phase($operation, 'active');
        }
    }

    public function completeHost(string $operation, string $proof): void
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        $hold = $this->files->operationState($operation);
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || 'complete' !== ($host['phase'] ?? null) || true !== ($host['control_runtime_verified'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || 'host' !== $hold['kind'] || ['proof_path' => $proof] !== $hold['evidence']) {
            throw new \RuntimeException('Only the exact fully verified host operation may retire its hold.');
        }
        if ('released' === $hold['phase']) {
            return; // Terminal completion must not project over later owner actions or claims.
        }
        $lease = $this->files->projectionLease();
        try {
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    public function completeLightNative(string $operation, string $proof): void
    {
        $record = $this->rootProof($proof);
        $hold = $this->files->operationState($operation);
        $identity = $record['identity'] ?? null;
        foreach (['binding', 'source_sha256', 'target_sha256'] as $field) {
            $value = $record[$field] ?? null;
            if (!\is_string($value) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $value)) {
                throw new \RuntimeException('Light native operation source binding is unavailable.');
            }
        }
        $latest = $this->connection->fetchAssociative("SELECT id,status FROM release_operation WHERE operation='update' ORDER BY started_at DESC,id DESC LIMIT 1");
        if ('emailer-light-native-update-v1' !== ($record['format'] ?? null) || $operation !== ($record['id'] ?? null) || !\is_array($identity) || $this->installation !== ($identity['installation_uuid'] ?? null) || 'completed' !== ($record['phase'] ?? null) || true !== ($record['fence_restored'] ?? null) || true !== ($record['control_runtime_verified'] ?? null) || 'native' !== $hold['kind'] || ['proof_path' => $proof] !== $hold['evidence'] || false === $latest || $operation !== $latest['id'] || 'succeeded' !== $latest['status']) {
            throw new \RuntimeException('The exact Light native operation has not completed live verification.');
        }
        if ('released' === $hold['phase']) {
            return;
        }
        if (true !== ($record['delivery_control_source_aware'] ?? null) && true !== ($record['retain_source_fence'] ?? null)) {
            // A legacy writer reopened before live verification can acknowledge
            // Pause only in the DB. Do not project inferred enabled intent over it.
            $this->files->invalidateUnprovenLegacyIntent();
        }
        $lease = $this->files->projectionLease();
        try {
            $holds = [];
            foreach ($this->files->status()['holds'] as $item) {
                $holds[$this->holdOperation($item)] = $item;
            }
            $previous = $record['previous_operation_id'] ?? null;
            $seen = [$operation => true];
            $retire = [];
            while (\is_string($previous) && isset($holds[$previous])) {
                if (isset($seen[$previous]) || 64 < \count($seen)) {
                    throw new \RuntimeException('Light recovery lineage is cyclic or exceeds its bound.');
                }
                $seen[$previous] = true;
                $priorHold = $holds[$previous];
                if ('native' !== $priorHold['kind'] || 'recovered' !== $priorHold['phase'] || ['proof_path' => $proof] !== $priorHold['evidence']) {
                    throw new \RuntimeException('A separate Light native operation still owns its hold.');
                }
                $prior = $this->rootProof(\dirname($proof).'/recovered-'.$previous.'.json');
                if ('emailer-light-native-update-v1' !== ($prior['format'] ?? null) || $previous !== ($prior['id'] ?? null) || 'recovered' !== ($prior['phase'] ?? null) || true !== ($prior['fence_restored'] ?? null) || ($record['binding'] ?? null) !== ($prior['binding'] ?? null) || ($record['source_sha256'] ?? null) !== ($prior['source_sha256'] ?? null)) {
                    throw new \RuntimeException('Recovered Light predecessor provenance is not verified.');
                }
                $retire[] = $previous;
                $previous = $prior['previous_operation_id'] ?? null;
            }
            foreach ($retire as $predecessor) {
                $this->files->phase($predecessor, 'released');
            }
            $this->publishProjection($operation);
        } finally {
            $lease->release();
        }
    }

    public function retainDeliveryReview(string $operation, string $proof): void
    {
        $record = $this->reviewProof($operation, $proof);
        if (!\is_int($record['delivery_review_count'] ?? null) || 0 >= $record['delivery_review_count']) {
            throw new \RuntimeException('The retained delivery review has no verified uncertain outcome.');
        }
        $this->files->hold($this->reviewOperation($record), 'recovery', ['reason' => 'uncertain_delivery', 'parent_operation' => $operation, 'proof_path' => $proof]);
    }

    public function acknowledgeDeliveryReview(string $operation, string $proof): void
    {
        $record = $this->reviewProof($operation, $proof);
        if (true !== ($record['delivery_review_acknowledged'] ?? null) || true !== ($record['control_release_completed'] ?? null)) {
            throw new \RuntimeException('Delivery outcome review requires an explicit operator acknowledgement after maintenance completion.');
        }
        $hold = $this->files->operationState($this->reviewOperation($record));
        if ('recovery' !== $hold['kind'] || ['reason' => 'uncertain_delivery', 'parent_operation' => $operation, 'proof_path' => $proof] !== $hold['evidence']) {
            throw new \RuntimeException('Delivery outcome review belongs to another operation.');
        }
        // Keep the DB gate held. Only the separate normal Resume action may
        // enable delivery after every other hold and readiness requirement passes.
        $this->files->phase($this->reviewOperation($record), 'released');
    }

    /** @return array<string, mixed> */
    private function reviewProof(string $operation, string $proof): array
    {
        $record = $this->rootProof($proof);
        $host = $record['host_handoff'] ?? null;
        if (!\is_array($host) || $this->installation !== ($host['installation_uuid'] ?? null) || $operation !== ($record['control_operation_id'] ?? null) || $this->privateDirectory !== ($record['control_private_directory'] ?? null) || 'complete' !== ($host['phase'] ?? null) || true !== ($host['control_runtime_verified'] ?? null) || !\is_string($record['delivery_review_operation_id'] ?? null)) {
            throw new \RuntimeException('Delivery outcome review identity is invalid.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function reviewOperation(array $record): string
    {
        $value = $record['delivery_review_operation_id'] ?? null;
        if (!\is_string($value)) {
            throw new \RuntimeException('Delivery outcome review operation is unavailable.');
        }

        return $value;
    }

    /** @param array<string, mixed> $hold */
    private function holdOperation(array $hold): string
    {
        $id = $hold['operation_id'] ?? null;
        if (!\is_string($id)) {
            throw new \RuntimeException('Light delivery hold identity is invalid.');
        }

        return $id;
    }

    private function publishProjection(string $operation): void
    {
        if ('released' === $this->files->operationState($operation)['phase']) {
            return;
        }
        $state = $this->files->status();
        $others = array_filter($state['holds'], static fn (array $hold): bool => $operation !== ($hold['operation_id'] ?? null));
        // Commit the database projection first. The durable operation file still
        // blocks claims until its final fsynced release; interruption fails closed.
        $enabled = 'enabled' === $state['owner'] && [] === $others;
        $this->connection->transactional(function () use ($enabled): void {
            $runtime = $this->connection->fetchAssociative("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
            if (false === $runtime || !is_numeric($runtime['active_claims']) || 0 !== (int) $runtime['active_claims']) {
                throw new \RuntimeException('Active delivery claims prevent maintenance completion.');
            }
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=?,updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'", [$enabled ? 1 : 0]);
        });
        $this->files->phase($operation, 'released');
    }

    /** @return array<string, mixed> */
    private function rootProof(string $path): array
    {
        for ($parent = \dirname($path);; $parent = \dirname($parent)) {
            $metadata = @lstat($parent);
            if (!\is_array($metadata) || 0o040000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022)) {
                throw new \RuntimeException('Installer delivery proof directory is unsafe.');
            }
            if ('/' === $parent) {
                break;
            }
        }
        $metadata = @lstat($path);
        if (!\is_array($metadata) || 0o100000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022) || 1 !== $metadata['nlink'] || 2097152 < $metadata['size']) {
            throw new \RuntimeException('Installer delivery proof is unsafe.');
        }
        $bytes = file_get_contents($path);
        $proof = false === $bytes ? null : json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!\is_array($proof)) {
            throw new \RuntimeException('Installer delivery proof is invalid.');
        }

        $record = [];
        foreach ($proof as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Installer delivery proof keys are invalid.');
            }
            $record[$key] = $value;
        }

        return $record;
    }
}
