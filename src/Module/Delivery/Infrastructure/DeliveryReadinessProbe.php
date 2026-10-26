<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class DeliveryReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe, \App\Module\Health\Application\Contract\MaintenanceReadinessProbe
{
    public function __construct(private Connection $connection, private string $environment, private ?\App\Shared\InstallerDeliveryHold $installerHold = null)
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

        return array_merge(['delivery_quiesced', 'worker_heartbeat', 'scheduler_heartbeat'], \is_string($installation) && true === $this->installerHold?->permitsOfflineVerification($installation) ? ['installer_delivery_hold'] : []);
    }

    public function findings(): array
    {
        $findings = [];
        if (true === $this->installerHold?->active()) {
            $findings[] = $this->critical('installer_delivery_hold', 'Installation host setup is unfinished.', 'Start the same verified setup package and choose Finish before resuming delivery.');
        }
        $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work, circuit_state, circuit_open_until FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || 1 !== $this->integer($runtime['schema_version'])) {
            $findings[] = $this->critical('delivery_schema', 'Delivery runtime compatibility is unavailable.', 'Apply the reviewed compatible schema before sending.');
        } elseif (1 !== $this->integer($runtime['accepting_work'])) {
            $findings[] = $this->critical('delivery_quiesced', 'An operational delivery hold is active.', 'Templates and campaign drafts remain available. Keep an intentional hold; when ready to send, configure email and review readiness before choosing Resume delivery.');
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
