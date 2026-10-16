<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\ReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use App\Module\Health\Application\Doctor;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DoctorTest extends TestCase
{
    public function testRuntimeVerificationRetainsDeliveryGatesAndRejectsUnrelatedCriticalFailure(): void
    {
        $probe = new class implements SendingGateReadinessProbe, \App\Module\Health\Application\Contract\MaintenanceReadinessProbe {
            public bool $brokenKey = false;
            public bool $idle = true;

            public function offlineVerificationReady(): bool
            {
                return $this->idle;
            }

            public function findings(): array
            {
                $findings = [
                    ['code' => 'sender_identity', 'severity' => 'critical', 'summary' => 'SMTP is deferred.', 'remediation' => 'Configure mail when ready.'],
                    ['code' => 'delivery_quiesced', 'severity' => 'critical', 'summary' => 'Delivery is held.', 'remediation' => 'Accept after verification.'],
                ];
                if ($this->brokenKey) {
                    $findings[] = ['code' => 'master_key_unavailable', 'severity' => 'critical', 'summary' => 'The key is unavailable.', 'remediation' => 'Recover the original key.'];
                }

                return $findings;
            }

            public function maintenanceFindings(): array
            {
                return ['sender_identity', 'delivery_quiesced'];
            }
        };
        $doctor = new Doctor([$probe], self::createStub(Connection::class));
        self::assertFalse($doctor->verifyRuntime()['runtime_verified']);
        self::assertTrue($doctor->verifyRuntime(false, true)['runtime_verified']);
        $probe->idle = false;
        self::assertFalse($doctor->verifyRuntime(false, true)['runtime_verified']);
        $probe->idle = true;
        $report = $doctor->verifyRuntime(true);
        self::assertTrue($report['runtime_verified']);
        self::assertFalse($report['delivery_ready']);
        self::assertSame(2, $report['critical_count']);
        self::assertIsArray($report['findings']);
        self::assertSame([true, true], array_column($report['findings'], 'sending_blocked'));
        $probe->idle = false;
        self::assertFalse($doctor->verifyRuntime(true)['runtime_verified']);
        $probe->idle = true;
        $probe->brokenKey = true;
        self::assertFalse($doctor->verifyRuntime(true)['runtime_verified']);
        self::assertSame(['master_key_unavailable'], $doctor->verifyRuntime(true)['runtime_blockers']);
        $this->expectException(\DomainException::class);
        $doctor->assertSendingReady();
    }

    public function testCriticalFindingFailsSendingClosedAndPersistsOnlyRedactedEvidence(): void
    {
        $probe = new class implements ReadinessProbe {
            public function findings(): array
            {
                return [['code' => 'smtp_transport_security', 'severity' => 'critical', 'summary' => 'Production SMTP is not ready.', 'remediation' => 'Configure authenticated TLS.']];
            }
        };
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('insert')->with('health_check_run', self::callback(static function (array $row): bool {
            $encoded = json_encode($row, JSON_THROW_ON_ERROR);

            return 'critical' === $row['status'] && 1 === $row['critical_count'] && !str_contains($encoded, 'password') && !str_contains($encoded, '@');
        }));
        $doctor = new Doctor([$probe], $connection);
        self::assertSame('critical', $doctor->run()['status']);

        $this->expectException(\DomainException::class);
        $doctor->assertSendingReady();
    }

    public function testProbeFailureBecomesAStableCriticalFinding(): void
    {
        $probe = new class implements ReadinessProbe {
            public function findings(): array
            {
                throw new \RuntimeException('credential@example.invalid');
            }
        };
        $connection = self::createStub(Connection::class);
        $result = (new Doctor([$probe], $connection))->run(false);
        self::assertStringStartsWith('probe_unavailable_', $result['findings'][0]['code']);
        self::assertStringNotContainsString('@', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testDistinctProbeFailuresHaveDistinctStableFindingCodes(): void
    {
        $first = new class implements ReadinessProbe {
            public function findings(): array
            {
                throw new \RuntimeException('first failure');
            }
        };
        $second = new class implements ReadinessProbe {
            public function findings(): array
            {
                throw new \RuntimeException('second failure');
            }
        };
        $doctor = new Doctor([$first, $second], self::createStub(Connection::class));

        $firstRun = $doctor->run(false);
        $secondRun = $doctor->run(false);
        $firstCodes = array_column($firstRun['findings'], 'code');
        $secondCodes = array_column($secondRun['findings'], 'code');

        self::assertCount(2, array_unique($firstCodes));
        self::assertSame($firstCodes, $secondCodes);
        foreach ($firstCodes as $code) {
            self::assertMatchesRegularExpression('/^probe_unavailable_[a-f0-9]{40}$/', $code);
        }
    }

    public function testMaintenanceVerificationDowngradesOnlyKnownQuiescenceFinding(): void
    {
        $probe = new class implements ReadinessProbe {
            public function findings(): array
            {
                return [
                    ['code' => 'delivery_quiesced', 'severity' => 'critical', 'summary' => 'maintenance', 'remediation' => 'resume'],
                    ['code' => 'master_key_unavailable', 'severity' => 'critical', 'summary' => 'key', 'remediation' => 'recover'],
                ];
            }
        };
        $result = (new Doctor([$probe], self::createStub(Connection::class)))->run(false, true);
        self::assertSame(1, $result['critical_count']);
        self::assertSame(1, $result['warning_count']);
        self::assertSame('warning', $result['findings'][1]['severity']);
    }

    public function testProbeCannotInjectRawAddressIntoPersistedReport(): void
    {
        $probe = new class implements ReadinessProbe {
            public function findings(): array
            {
                return [['code' => 'unsafe', 'severity' => 'critical', 'summary' => 'recipient@example.test', 'remediation' => 'repair']];
            }
        };
        $result = (new Doctor([$probe], self::createStub(Connection::class)))->run(false);
        self::assertStringStartsWith('probe_unavailable_', $result['findings'][0]['code']);
        self::assertStringNotContainsString('@', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testSendingGateIgnoresCriticalOperationalProbeThatIsNotADeclaredGate(): void
    {
        $operational = new class implements LightweightReadinessProbe {
            public function findings(): array
            {
                return [['code' => 'backup_recency', 'severity' => 'critical', 'summary' => 'Backup is overdue.', 'remediation' => 'Create a backup.']];
            }
        };
        $sending = new class implements SendingGateReadinessProbe {
            public function findings(): array
            {
                return [];
            }
        };

        (new Doctor([$operational, $sending], self::createStub(Connection::class)))->assertSendingReady();
        self::addToAssertionCount(1);
    }

    public function testLightweightRunExcludesFullOnlyProbe(): void
    {
        $lightweight = new class implements LightweightReadinessProbe {
            public function findings(): array
            {
                return [['code' => 'lightweight_warning', 'severity' => 'warning', 'summary' => 'A lightweight warning.', 'remediation' => 'Review the warning.']];
            }
        };
        $fullOnly = new class implements ReadinessProbe {
            public function findings(): array
            {
                return [['code' => 'full_only', 'severity' => 'critical', 'summary' => 'A full-only issue.', 'remediation' => 'Run full Doctor.']];
            }
        };

        $result = (new Doctor([$lightweight, $fullOnly], self::createStub(Connection::class)))->run(false, false, 'lightweight', 'login');
        self::assertSame('warning', $result['status']);
        self::assertSame('lightweight_warning', $result['findings'][0]['code']);
    }
}
