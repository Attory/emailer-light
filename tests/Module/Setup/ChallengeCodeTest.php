<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup;

use App\Module\Setup\Application\ChallengeCode;
use PHPUnit\Framework\TestCase;

final class ChallengeCodeTest extends TestCase
{
    public function testEncodesExactlyOneHundredAndSixtyBitsAsCrockfordBase32(): void
    {
        $encoded = ChallengeCode::encode(str_repeat("\0", 20));

        self::assertSame(32, \strlen($encoded));
        self::assertSame('00000000000000000000000000000000', $encoded);
        self::assertSame('0000-0000-0000-0000-0000-0000-0000-0000', ChallengeCode::display($encoded));
    }

    public function testNormalizationAcceptsGroupingAndLineEndingsOnly(): void
    {
        self::assertSame(
            '0123456789ABCDEFGHJKMNPQRSTVWXYZ',
            ChallengeCode::normalize("0123-4567-89AB-CDEF-GHJK-MNPQ-RSTV-WXYZ\r\n"),
        );
        self::assertNull(ChallengeCode::normalize(' 0123-4567-89AB-CDEF-GHJK-MNPQ-RSTV-WXYZ'));
        self::assertNull(ChallengeCode::normalize('0123-4567-89AB-CDEF-GHJK-MNPQ-RSTV-WXYZ '));
        self::assertNull(ChallengeCode::normalize('0123-4567-89AB-CDEF-GHIL-MNPQ-RSTV-WXYZ'));
    }
}
