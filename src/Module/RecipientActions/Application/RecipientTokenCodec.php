<?php

declare(strict_types=1);

namespace App\Module\RecipientActions\Application;

final class RecipientTokenCodec
{
    public function encode(int $version, string $handle, string $purpose, string $campaignId, string $recipientId, string $audienceRecipientId, string $expiresAt, string $key): string
    {
        $this->assertInputs($version, $handle, $key);
        $mac = hash_hmac('sha256', $this->payload($version, $handle, $purpose, $campaignId, $recipientId, $audienceRecipientId, $expiresAt), $key, true);

        return 'v'.$version.'.'.$handle.'.'.$this->base64Url($mac);
    }

    public function verify(string $token, int $version, string $handle, string $purpose, string $campaignId, string $recipientId, string $audienceRecipientId, string $expiresAt, string $key): bool
    {
        try {
            $expected = $this->encode($version, $handle, $purpose, $campaignId, $recipientId, $audienceRecipientId, $expiresAt, $key);
        } catch (\Throwable) {
            return false;
        }

        return hash_equals($expected, $token);
    }

    /** @return array{version: int, handle: string}|null */
    public function parse(string $token): ?array
    {
        if (1 !== preg_match('/^v([1-9][0-9]{0,4})\.([A-Za-z0-9_-]{43})\.[A-Za-z0-9_-]{43}$/D', $token, $matches)) {
            return null;
        }

        return ['version' => (int) $matches[1], 'handle' => $matches[2]];
    }

    private function payload(int $version, string $handle, string $purpose, string $campaignId, string $recipientId, string $audienceRecipientId, string $expiresAt): string
    {
        return implode("\0", ['emailer-light-recipient-token', (string) $version, $handle, $purpose, $campaignId, $recipientId, $audienceRecipientId, $expiresAt]);
    }

    private function assertInputs(int $version, string $handle, string $key): void
    {
        if (1 > $version || 65_535 < $version || 1 !== preg_match('/^[A-Za-z0-9_-]{43}$/D', $handle) || 32 !== \strlen($key)) {
            throw new \InvalidArgumentException('Recipient token material is invalid.');
        }
    }

    private function base64Url(string $value): string
    {
        return sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    }
}
