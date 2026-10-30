<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class DeliveryReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe, \App\Module\Health\Application\Contract\MaintenanceReadinessProbe
{
    public function __construct(private Connection $connection, private string $environment, private ?\App\Shared\InstallerDeliveryHold $installerHold = null, private ?\App\Module\Delivery\Application\Contract\DeliveryControl $control = null)
    {
    }

    public function offlineVerificationReady(): bool
    {
        $runtime = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");

        return false !== $runtime && 0 === $this->integer($runtime['accepting_work']) && 0 === $this->integer($runtime['active_claims']);
    }

    public function maintenanceFindings(): array
    {
        $runtime = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || 0 !== $this->integer($runtime['accepting_work']) || 0 !== $this->integer($runtime['active_claims'])) {
            return [];
        }

        // Caller must explicitly select offline maintenance. Live runtime checks
        // still require current supervised-worker heartbeats.
        $installation = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");

        return array_merge(['delivery_quiesced', 'operation_delivery_hold', 'delivery_outcome_review', 'worker_heartbeat', 'scheduler_heartbeat'], \is_string($installation) && true === $this->installerHold?->permitsOfflineVerification($installation) ? ['installer_delivery_hold'] : []);
    }

    public function findings(): array
    {
        $findings = [];
        $control = $this->control?->status();
        if (null !== $control && [] !== $control['holds']) {
            foreach ($control['holds'] as $hold) {
                $phase = \is_string($hold['phase'] ?? null) ? $hold['phase'] : 'unknown';
                if ('recovery' === ($hold['kind'] ?? null) && \is_array($hold['evidence'] ?? null) && 'uncertain_delivery' === ($hold['evidence']['reason'] ?? null)) {
                    $findings[] = $this->critical('delivery_outcome_review', 'Maintenance completed; uncertain delivery outcomes need review.', 'Review delivery history without retrying uncertain or failed work. Use this operation’s verified setup Resume action to acknowledge the review; independent holds and normal sending readiness remain enforced.');
                    continue;
                }
                $action = 'manual' === ($hold['kind'] ?? null) ? 'Ask the issuing operator to release this independent hold.' : ('recovered' === $phase ? 'The source was recovered. Review it through the exact verified setup before explicitly resuming delivery.' : 'Use this operation’s verified setup to Finish or Recover it.');
                $findings[] = $this->critical('operation_delivery_hold', 'Operation '.(\is_string($hold['operation_id'] ?? null) ? $hold['operation_id'] : 'unknown').' owns a delivery hold ('.$phase.').', $action.' Independent owner pauses are preserved.');
            }
        }
        if (true === $this->installerHold?->active()) {
            $findings[] = $this->critical('installer_delivery_hold', 'Installation host setup is unfinished.', 'Start the same verified setup package and choose Finish before resuming delivery.');
        }
        $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work, circuit_state, circuit_open_until FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || 1 !== $this->integer($runtime['schema_version'])) {
            $findings[] = $this->critical('delivery_schema', 'Delivery runtime compatibility is unavailable.', 'Apply the reviewed compatible schema before sending.');
        } elseif ((1 !== $this->integer($runtime['accepting_work']) && !(null !== $control && $control['configured'] && 'enabled' === $control['owner'] && [] !== $control['holds'])) || (null !== $control && $control['configured'] && 'enabled' !== $control['owner'])) {
            $summary = null !== $control && $control['configured'] ? ('paused' === $control['owner'] ? 'Delivery is paused by its owner.' : 'Earlier delivery intent is unverified; owner review is required.') : 'An operational delivery hold is active.';
            $findings[] = $this->critical('delivery_quiesced', $summary, 'Templates and campaign drafts remain available. Keep an intentional hold; when ready to send, configure email and review readiness before choosing Resume delivery.');
        } elseif ('open' === $runtime['circuit_state']) {
            $openUntil = $runtime['circuit_open_until'];
            if (!\is_string($openUntil) || new \DateTimeImmutable($openUntil, new \DateTimeZone('UTC')) > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
                $findings[] = $this->critical('delivery_circuit_open', 'The delivery circuit breaker is open.', 'Resolve the transport failure and allow the bounded recovery policy to close it.');
            }
        }
        if (!\in_array($this->environment, ['dev', 'test'], true)) {
            foreach (['worker', 'scheduler'] as $kind) {
                $fresh = $this->connection->fetchOne('SELECT 1 FROM worker_heartbeat WHERE worker_kind = ? AND stopped_at IS NULL AND heartbeat_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 5 MINUTE) LIMIT 1', [$kind]);
                if (false === $fresh) {
                    $findings[] = $this->critical($kind.'_heartbeat', ucfirst($kind).' heartbeat is stale or absent.', 'Restore the supervised '.$kind.' and rerun Doctor.');
                }
            }
        }

        return $findings;
    }

    /** @return array{code: string, severity: 'critical', summary: string, remediation: string} */
    private function critical(string $code, string $summary, string $remediation): array
    {
        return ['code' => $code, 'severity' => 'critical', 'summary' => $summary, 'remediation' => $remediation];
    }

    private function integer(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Delivery numeric state is invalid.');
        }

        return (int) $value;
    }
}
