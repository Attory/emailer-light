<?php

declare(strict_types=1);

namespace App\Tests\Module\Health;

use App\Module\Release\Application\Contract\InstallerFailure;
use PHPUnit\Framework\TestCase;

final class InstallerFailureTest extends TestCase
{
    public function testFailureCategoryNeverContainsExceptionDetailsOrCredentials(): void
    {
        self::assertSame(['installer_error' => 'verification_failed'], InstallerFailure::report(new \RuntimeException('mysql://synthetic-user:synthetic-sensitive-value@private-host/database')));
        self::assertSame(['installer_error' => 'schema_mismatch'], InstallerFailure::report(new \RuntimeException('The live database differs from the authenticated Light baseline. No update or schema repair was attempted.')));
        self::assertSame(['installer_error' => 'finish_required'], InstallerFailure::report(new \RuntimeException('The update succeeded, but its final account-restoration receipt is incomplete.')));
        self::assertSame(['installer_error' => 'delivery_busy'], InstallerFailure::report(new \RuntimeException('Active claims prevent native recovery; no claim is reset.')));
    }
}
