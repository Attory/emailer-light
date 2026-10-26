<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use App\Shared\UpdateDeliveryState;
use PHPUnit\Framework\TestCase;

final class UpdateDeliveryStateTest extends TestCase
{
    public function testAbsentUpdateHasNoDeliverySideEffect(): void
    {
        $state = new UpdateDeliveryState(sys_get_temp_dir().'/absent-update-'.bin2hex(random_bytes(8)).'/private');
        self::assertFalse($state->status()['blocked']);
        $state->assertSendingAllowed();
        $state->assertResumeAllowed();
    }

    public function testUnsafeAndMalformedEvidenceCannotEnableSending(): void
    {
        $directory = sys_get_temp_dir().'/update-evidence-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $file = $directory.'/update-maintenance.json';
        try {
            file_put_contents($file, '{broken');
            $state = new UpdateDeliveryState($directory.'/private');
            self::assertSame('unknown', $state->status()['phase']);
            self::assertTrue($state->status()['blocked']);
            unlink($file);
            symlink('/dev/null', $file);
            self::assertSame('unknown', $state->status()['phase']);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }

    public function testRootOwnedPhasesNeverInventPausedDraftsOrAllowRecoveredResume(): void
    {
        if (0 !== posix_geteuid()) {
            self::markTestSkipped('Positive root-owned evidence is exercised by the root installer acceptance gate.');
        }
        $directory = sys_get_temp_dir().'/update-phases-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $file = $directory.'/update-maintenance.json';
        $id = '01900000-0000-7000-8000-000000000001';
        $state = new UpdateDeliveryState($directory.'/private');
        $snapshot = ['format' => 'emailer-update-delivery-v1', 'phase' => 'in_progress', 'prior_accepting_work' => 1, 'campaigns' => [['id' => $id, 'state' => 'draft', 'remaining' => 2]]];
        try {
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            self::assertSame([], $state->status()['paused_campaign_ids']);
            $snapshot['campaigns'][0]['state'] = 'sending';
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            self::assertSame([$id], $state->status()['paused_campaign_ids']);
            $snapshot['prior_accepting_work'] = 0;
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            self::assertSame([], $state->status()['paused_campaign_ids']);
            $snapshot['phase'] = 'recovered';
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            try {
                $state->assertResumeAllowed();
                self::fail('Only root-guided resuming may lift the update hold.');
            } catch (\DomainException) {
                self::assertTrue($state->status()['blocked']);
            }
            $snapshot['phase'] = 'resuming';
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            $state->assertResumeAllowed();
            self::assertTrue($state->status()['blocked']);
            $snapshot['phase'] = 'complete';
            file_put_contents($file, json_encode($snapshot, JSON_THROW_ON_ERROR));
            $state->assertSendingAllowed();
            self::assertFalse($state->status()['blocked']);
        } finally {
            unlink($file);
            rmdir($directory);
        }
    }
}
