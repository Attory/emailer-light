<?php

declare(strict_types=1);

namespace App\Tests\Module\RecipientActions;

use App\Module\RecipientActions\Application\RecipientTokenCodec;
use PHPUnit\Framework\TestCase;

final class RecipientTokenCodecTest extends TestCase
{
    public function testTokenIsPurposeScopedTamperEvidentAndVersioned(): void
    {
        $codec = new RecipientTokenCodec();
        $key = random_bytes(32);
        $handle = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
        $campaign = '018f0000-0000-7000-8000-000000000001';
        $recipient = '018f0000-0000-7000-8000-000000000002';
        $contact = '018f0000-0000-7000-8000-000000000003';
        $expires = '2027-08-23 00:00:00.000000';
        $token = $codec->encode(1, $handle, 'unsubscribe', $campaign, $recipient, $contact, $expires, $key);

        self::assertSame(['version' => 1, 'handle' => $handle], $codec->parse($token));
        self::assertTrue($codec->verify($token, 1, $handle, 'unsubscribe', $campaign, $recipient, $contact, $expires, $key));
        self::assertFalse($codec->verify($token, 1, $handle, 'view', $campaign, $recipient, $contact, $expires, $key));
        self::assertFalse($codec->verify($token, 1, $handle, 'unsubscribe', $campaign, $recipient, $contact, $expires, random_bytes(32)));
        self::assertFalse($codec->verify(substr($token, 0, -1).('A' === substr($token, -1) ? 'B' : 'A'), 1, $handle, 'unsubscribe', $campaign, $recipient, $contact, $expires, $key));
        self::assertSame(2, $codec->parse('v2.'.substr($token, 3))['version'] ?? null);
    }
}
