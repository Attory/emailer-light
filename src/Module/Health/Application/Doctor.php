<?php

declare(strict_types=1);

namespace App\Module\Health\Application;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\ReadinessProbe;
use App\Module\Health\Application\Contract\ScheduledHealthOperations;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class Doctor implements ScheduledHealthOperations, Contract\RuntimeVerification
{
    /** @param iterable<ReadinessProbe> $probes */
    public function __construct(private iterable $probes, private Connection $connection)
    {
    }

    /** @return array{id: string, status: 'healthy'|'warning'|'critical', critical_count: int, warning_count: int, findings: list<array{code: string, severity: 'critical'|'warning', summary: string, risk: string, evidence: string, remediation: string, sending_blocked: bool}>, started_at: string, finished_at: string, run_type: 'lightweight'|'full', trigger_source: 'login'|'manual'|'daily'|'update'} */
    public function run(bool $persist = true, bool $maintenanceVerification = false, string $runType = 'full', string $triggerSource = 'manual'): array
    {
        if (!\in_array($runType, ['lightweight', 'full'], true) || !\in_array($triggerSource, ['login', 'manual', 'daily', 'update'], true)) {
            throw new \InvalidArgumentException('Doctor run classification is invalid.');
        }

        $started = Identity::now();
        $findings = [];
        foreach ($this->probes as $probe) {
            if ('lightweight' === $runType && !$probe instanceof LightweightReadinessProbe) {
                continue;
            }
            $sendingBlocked = $probe instanceof SendingGateReadinessProbe;
            try {
                foreach ($probe->findings() as $finding) {
                    $findings[] = $this->normalizeFinding($finding, $sendingBlocked);
                }
            } catch (\Throwable) {
                $findings[] = $this->failedProbeFinding($probe, $sendingBlocked);
            }
        }
        if ($maintenanceVerification) {
            foreach ($findings as &$finding) {
                if ('delivery_quiesced' === $finding['code'] && 'critical' === $finding['severity']) {
                    $finding['severity'] = 'warning';
                    $finding['summary'] = 'Delivery is safely quiesced for a supervised maintenance verification.';
                    $finding['risk'] = 'Delivery cannot progress until the supervised maintenance window ends.';
                    $finding['remediation'] = 'Resume delivery only after this verification succeeds.';
                    $finding['sending_blocked'] = false;
                }
            }
            unset($finding);
        }
        usort($findings, static fn (array $a, array $b): int => [$a['severity'], $a['code']] <=> [$b['severity'], $b['code']]);
        $critical = \count(array_filter($findings, static fn (array $finding): bool => 'critical' === $finding['severity']));
        $warning = \count($findings) - $critical;
        $status = 0 < $critical ? 'critical' : (0 < $warning ? 'warning' : 'healthy');
        $finished = Identity::now();
        $id = Identity::uuidV7();
        if ($persist) {
            $this->connection->beginTransaction();
            try {
                $this->connection->insert('health_check_run', [
                    'id' => $id,
                    'run_type' => $runType,
                    'trigger_source' => $triggerSource,
                    'status' => $status,
                    'critical_count' => $critical,
                    'warning_count' => $warning,
                    'findings_json' => json_encode($findings, JSON_THROW_ON_ERROR),
                    'started_at' => $started,
                    'finished_at' => $finished,
                ]);
                $this->persistFindingState($id, $findings, $finished, $runType);
                $this->connection->commit();
            } catch (\Throwable $exception) {
                if ($this->connection->isTransactionActive()) {
                    $this->connection->rollBack();
                }
                throw $exception;
            }
        }

        return ['id' => $id, 'status' => $status, 'critical_count' => $critical, 'warning_count' => $warning, 'findings' => $findings, 'started_at' => $started, 'finished_at' => $finished, 'run_type' => $runType, 'trigger_source' => $triggerSource];
    }

    /** @return array<string, mixed> */
    public function runLightweightCached(int $ttlSeconds = 900): array
    {
        if (60 > $ttlSeconds || 3600 < $ttlSeconds) {
            throw new \InvalidArgumentException('Doctor login cache duration is invalid.');
        }
        $row = $this->connection->fetchAssociative("SELECT id, run_type, trigger_source, status, critical_count, warning_count, findings_json, started_at, finished_at FROM health_check_run WHERE run_type = 'lightweight' AND finished_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND) ORDER BY finished_at DESC LIMIT 1", [$ttlSeconds]);
        if (false !== $row) {
            return $this->hydrateRun($row);
        }

        return $this->run(true, false, 'lightweight', 'login');
    }

    public function runScheduled(): array
    {
        return $this->run(true, false, 'full', 'daily');
    }

    public function assertSendingReady(): void
    {
        $explicitOnly = false;
        foreach ($this->probes as $probe) {
            if ($probe instanceof SendingGateReadinessProbe) {
                $explicitOnly = true;
                break;
            }
        }
        $critical = 0;
        foreach ($this->probes as $probe) {
            if ($explicitOnly && !$probe instanceof SendingGateReadinessProbe) {
                continue;
            }
            try {
                foreach ($probe->findings() as $finding) {
                    if ('critical' === $this->normalizeFinding($finding, true)['severity']) {
                        ++$critical;
                    }
                }
            } catch (\Throwable) {
                ++$critical;
            }
        }
        if (0 < $critical) {
            throw new \DomainException('Sending is blocked by critical readiness findings.');
        }
    }

    /** @return array<string, mixed> */
    public function verifyRuntime(bool $offlineMaintenance = false, bool $allowOperationalHold = false): array
    {
        // Preserve every original severity and sending gate in the report.
        $result = $this->run(false);
        $expected = ['sender_identity'];
        $blocking = [];
        foreach ($this->probes as $probe) {
            if ($probe instanceof Contract\MaintenanceReadinessProbe) {
                if ($offlineMaintenance && !$probe->offlineVerificationReady()) {
                    $blocking[] = 'offline_not_quiesced';
                }
                $codes = $probe->maintenanceFindings();
                foreach ($codes as $code) {
                    if ($offlineMaintenance || \in_array($code, ['sender_identity', 'smtp_transport_security'], true) || ($allowOperationalHold && $probe->offlineVerificationReady() && 'delivery_quiesced' === $code)) {
                        $expected[] = $code;
                    }
                }
            }
        }
        foreach ($result['findings'] as $finding) {
            if ('critical' === $finding['severity'] && !\in_array($finding['code'], $expected, true)) {
                $blocking[] = $finding['code'];
            }
        }
        $result['runtime_verified'] = [] === $blocking;
        $result['runtime_blockers'] = $blocking;
        $result['delivery_ready'] = [] === array_filter($result['findings'], static fn (array $finding): bool => $finding['sending_blocked']);

        return $result;
    }

    /** @return list<array<string, mixed>> */
    public function activeFindings(): array
    {
        return $this->connection->fetchAllAssociative("SELECT code, severity, summary, risk, evidence, remediation, sending_blocked, first_observed_at, last_observed_at, acknowledged_at FROM health_finding_state WHERE active = 1 ORDER BY FIELD(severity, 'critical', 'warning'), code");
    }

    public function acknowledge(string $code, string $actorId): bool
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $code)) {
            return false;
        }

        return 1 === $this->connection->update('health_finding_state', ['acknowledged_at' => Identity::now(), 'acknowledged_by' => $actorId], ['code' => $code, 'active' => 1]);
    }

    /** @return list<array<string, mixed>> */
    public function recent(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, run_type, trigger_source, status, critical_count, warning_count, findings_json, started_at, finished_at FROM health_check_run ORDER BY finished_at DESC LIMIT 20');

        return array_map(fn (array $row): array => $this->hydrateRun($row), $rows);
    }

    /** @param array<mixed> $finding
     * @return array{code: string, severity: 'critical'|'warning', summary: string, risk: string, evidence: string, remediation: string, sending_blocked: bool}
     */
    private function normalizeFinding(array $finding, bool $sendingBlocked): array
    {
        $allowed = ['code', 'severity', 'summary', 'risk', 'evidence', 'remediation', 'sending_blocked'];
        if ([] !== array_diff(array_keys($finding), $allowed)
            || !isset($finding['code'], $finding['severity'], $finding['summary'], $finding['remediation'])
            || !\is_string($finding['code'])
            || 1 !== preg_match('/^[a-z][a-z0-9_]{1,63}$/', $finding['code'])
            || !\in_array($finding['severity'], ['critical', 'warning'], true)
            || !\is_string($finding['summary'])
            || !\is_string($finding['remediation'])
        ) {
            throw new \UnexpectedValueException('Readiness finding is unsafe.');
        }
        $risk = \is_string($finding['risk'] ?? null) ? $finding['risk'] : $finding['summary'];
        $evidence = \is_string($finding['evidence'] ?? null) ? $finding['evidence'] : 'A redacted readiness probe reported this condition.';
        foreach ([$finding['summary'], $risk, $evidence, $finding['remediation']] as $text) {
            if ('' === $text || 240 < \strlen($text) || str_contains($text, '@') || 1 === preg_match('/[\x00-\x1F\x7F]/', $text)) {
                throw new \UnexpectedValueException('Readiness finding is unsafe.');
            }
        }

        return ['code' => $finding['code'], 'severity' => $finding['severity'], 'summary' => $finding['summary'], 'risk' => $risk, 'evidence' => $evidence, 'remediation' => $finding['remediation'], 'sending_blocked' => $sendingBlocked && 'critical' === $finding['severity']];
    }

    /** @return array{code: string, severity: 'critical'|'warning', summary: string, risk: string, evidence: string, remediation: string, sending_blocked: bool} */
    private function failedProbeFinding(ReadinessProbe $probe, bool $sendingBlocked): array
    {
        $probeCode = substr(hash('sha256', $probe::class), 0, 40);

        return $this->normalizeFinding(['code' => 'probe_unavailable_'.$probeCode, 'severity' => 'critical', 'summary' => 'A readiness check could not complete.', 'remediation' => 'Inspect the affected runtime component and rerun Doctor.'], $sendingBlocked);
    }

    /** @param list<array<string, mixed>> $findings */
    private function persistFindingState(string $runId, array $findings, string $observedAt, string $runType): void
    {
        if ('full' === $runType) {
            $this->connection->executeStatement('UPDATE health_finding_state SET active = 0 WHERE active = 1');
        }
        foreach ($findings as $finding) {
            $this->connection->executeStatement(<<<'SQL'
INSERT INTO health_finding_state (code, severity, summary, risk, evidence, remediation, sending_blocked, active, first_observed_at, last_observed_at, last_run_id)
VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
ON DUPLICATE KEY UPDATE severity = VALUES(severity), summary = VALUES(summary), risk = VALUES(risk), evidence = VALUES(evidence), remediation = VALUES(remediation), sending_blocked = VALUES(sending_blocked), active = 1, last_observed_at = VALUES(last_observed_at), last_run_id = VALUES(last_run_id)
SQL, [$finding['code'], $finding['severity'], $finding['summary'], $finding['risk'], $finding['evidence'], $finding['remediation'], true === $finding['sending_blocked'] ? 1 : 0, $observedAt, $observedAt, $runId]);
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrateRun(array $row): array
    {
        try {
            $decoded = \is_string($row['findings_json'] ?? null) ? json_decode($row['findings_json'], true, 8, JSON_THROW_ON_ERROR) : [];
        } catch (\Throwable) {
            $decoded = [];
        }
        $findings = [];
        if (\is_array($decoded)) {
            foreach ($decoded as $finding) {
                try {
                    if (\is_array($finding)) {
                        $findings[] = $this->normalizeFinding($finding, true === ($finding['sending_blocked'] ?? false));
                    }
                } catch (\Throwable) {
                }
            }
        }
        unset($row['findings_json']);
        $row['findings'] = $findings;

        return $row;
    }
}
