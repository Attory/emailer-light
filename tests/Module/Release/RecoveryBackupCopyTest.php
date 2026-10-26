<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Backup\Application\Contract\ExternalBackupDestination;
use App\Module\Operations\Application\Contract\OperationsPolicyReader;
use App\Module\Release\Application\Contract\InstallerFailure;
use App\Module\Release\Application\Contract\RuntimeVerificationFailed;
use App\Module\Release\Application\RecoveryBackupCopy;
use PHPUnit\Framework\TestCase;

final class RecoveryBackupCopyTest extends TestCase
{
    /** @return array{id: string, path: string, sha256: string, bytes: int} */
    private function backup(): array
    {
        return ['id' => '01990000-0000-7000-8000-000000000001', 'path' => '/private/backup-01990000-0000-7000-8000-000000000001.enxbackup', 'sha256' => str_repeat('a', 64), 'bytes' => 123];
    }

    public function testDisabledAutomationDoesNotContactOrChangeADestination(): void
    {
        $policy = self::createStub(OperationsPolicyReader::class);
        $policy->method('current')->willReturn(['backup_automation_enabled' => 0, 'backup_destination_type' => 'filesystem']);
        $destination = self::createMock(ExternalBackupDestination::class);
        $destination->expects(self::never())->method('available');
        $destination->expects(self::never())->method('transfer');
        $destination->expects(self::never())->method('delete');
        (new RecoveryBackupCopy($destination, $policy))->preserve($this->backup());
    }

    public function testEnabledPolicyCopiesTheSameVerifiedSafetyBackupWithoutCreatingAnotherOrRunningRetention(): void
    {
        $policy = self::createStub(OperationsPolicyReader::class);
        $policy->method('current')->willReturn(['backup_automation_enabled' => 1, 'backup_destination_type' => 'filesystem']);
        $destination = self::createMock(ExternalBackupDestination::class);
        $destination->expects(self::once())->method('available')->willReturn(true);
        $destination->expects(self::once())->method('transfer')->with($this->backup());
        $destination->expects(self::never())->method('delete');
        (new RecoveryBackupCopy($destination, $policy))->preserve($this->backup());
    }

    public function testUnavailableApprovedDestinationStopsBeforeCopyOrPublicationWithABoundedBlocker(): void
    {
        $policy = self::createStub(OperationsPolicyReader::class);
        $policy->method('current')->willReturn(['backup_automation_enabled' => 1, 'backup_destination_type' => 'filesystem']);
        $destination = self::createMock(ExternalBackupDestination::class);
        $destination->expects(self::once())->method('available')->willReturn(false);
        $destination->expects(self::never())->method('transfer');
        try {
            (new RecoveryBackupCopy($destination, $policy))->preserve($this->backup());
            self::fail('An unavailable approved destination was ignored.');
        } catch (RuntimeVerificationFailed $failure) {
            self::assertSame(['installer_error' => 'verification_failed', 'runtime_blockers' => ['backup_destination']], InstallerFailure::report($failure));
        }
    }

    public function testTransferFailurePreservesTheRealCategoryWithoutDisclosingDestinationDiagnostics(): void
    {
        $policy = self::createStub(OperationsPolicyReader::class);
        $policy->method('current')->willReturn(['backup_automation_enabled' => 1, 'backup_destination_type' => 'filesystem']);
        $destination = self::createStub(ExternalBackupDestination::class);
        $destination->method('available')->willReturn(true);
        $destination->method('transfer')->willThrowException(new \RuntimeException('Synthetic private destination detail'));
        try {
            (new RecoveryBackupCopy($destination, $policy))->preserve($this->backup());
            self::fail('A failed copy was ignored.');
        } catch (RuntimeVerificationFailed $failure) {
            self::assertSame(['backup_external_recency'], $failure->blockers);
            self::assertNull($failure->getPrevious());
            self::assertStringNotContainsString('Synthetic private', $failure->getMessage());
            $wrapped = new \RuntimeException('Release verification failed.', previous: $failure);
            self::assertSame(['installer_error' => 'verification_failed', 'runtime_blockers' => ['backup_external_recency']], InstallerFailure::report($wrapped));
            $recoveryFailed = new \RuntimeException('Automatic rollback failed.', previous: $failure);
            self::assertSame(['installer_error' => 'recovery_required'], InstallerFailure::report($recoveryFailed));
        }
    }

    public function testInvalidOrExcessiveBlockerDataCannotEscapeAsDiagnosticText(): void
    {
        foreach ([[], ['not a safe code: synthetic private'], array_fill(0, 65, 'backup_destination')] as $input) {
            self::assertSame(['runtime_verification'], (new RuntimeVerificationFailed($input))->blockers);
        }
    }
}
