<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use App\Shared\InstallerDeliveryHold;
use PHPUnit\Framework\TestCase;

final class InstallerDeliveryHoldTest extends TestCase
{
    public function testAnUnconfiguredHoldDoesNotAffectExistingInstallations(): void
    {
        $hold = new InstallerDeliveryHold();
        self::assertFalse($hold->active());
        self::assertFalse($hold->permitsOfflineVerification('synthetic-installation'));
    }

    public function testApplicationWritableAncestorsFailClosedEvenWhenHoldIsMissing(): void
    {
        $hold = new InstallerDeliveryHold(sys_get_temp_dir().'/emailer-missing-hold-'.bin2hex(random_bytes(8)).'.json');
        self::assertTrue($hold->active());
        self::assertFalse($hold->permitsOfflineVerification('synthetic-installation'));
    }

    public function testRelativeOrTraversalPathsCannotCreateAnOfflineExemption(): void
    {
        foreach (['hold.json', '/etc/../tmp/hold.json', '/etc//hold.json'] as $path) {
            $hold = new InstallerDeliveryHold($path);
            self::assertTrue($hold->active());
            self::assertFalse($hold->permitsOfflineVerification('synthetic-installation'));
        }
    }
}
