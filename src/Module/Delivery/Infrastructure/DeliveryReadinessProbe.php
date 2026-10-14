<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use Doctrine\DBAL\Connection;

final readonly class DeliveryReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe
{
    public function __construct(private Connection $connection, private string $environment)
    {
    }

    public function findings(): array
    {
        $findings = [];
        $runtime = $this->connection->fetchAssociative("SELECT schema_version, accepting_work, circuit_state, circuit_open_until FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || 1 !== $this->integer($runtime['schema_version'])) {
            $findings[] = $this->critical('delivery_schema', 'Delivery runtime compatibility is unavailable.', 'Apply the reviewed compatible schema before sending.');
        } elseif (1 !== $this->integer($runtime['accepting_work'])) {
            $findings[] = $this->critical('delivery_quiesced', 'Delivery is in maintenance mode.', 'Complete the maintenance operation and explicitly resume delivery.');
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
