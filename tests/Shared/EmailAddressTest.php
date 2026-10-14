<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\EmailAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EmailAddressTest extends TestCase
{
    public function testCanonicalizationPreservesLocalPartAndNormalizesDomainOnly(): void
    {
        $address = new EmailAddress('  First.Last+News@EXAMPLE.COM  ');

        self::assertSame('First.Last+News@EXAMPLE.COM', $address->entered);
        self::assertSame('First.Last+News@example.com', $address->canonical);
        self::assertSame('F***@E***.COM', $address->masked());
    }

    #[DataProvider('invalidAddresses')]
    public function testInvalidAddressesAreRejected(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EmailAddress($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidAddresses(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["victim@example.test\nBcc: attacker@example.test"];
        yield 'missing domain' => ['local-only'];
        yield 'oversized' => [str_repeat('a', 250).'@example.test'];
    }
}
