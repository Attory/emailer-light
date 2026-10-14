<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use App\Module\Secrets\Application\SecretCipher;
use App\Module\Secrets\Application\SecretContext;
use PHPUnit\Framework\TestCase;

final class SecretCipherTest extends TestCase
{
    private SecretCipher $cipher;
    private string $key;
    private SecretContext $context;

    protected function setUp(): void
    {
        $this->cipher = new SecretCipher();
        $this->key = random_bytes(32);
        $this->context = new SecretContext('018f6f4b-03d2-7aaa-8aaa-000000000001', 'smtp.credential', '018f6f4b-03d2-7bbb-8bbb-000000000002');
    }

    protected function tearDown(): void
    {
        $key = $this->key;
        sodium_memzero($key);
    }

    public function testRoundTripUsesFreshNonceAndDoesNotExposePlaintext(): void
    {
        $plaintext = 'synthetic-phase3-credential';
        $first = $this->cipher->encrypt($plaintext, $this->key, $this->context);
        $second = $this->cipher->encrypt($plaintext, $this->key, $this->context);

        self::assertNotSame($first, $second);
        self::assertStringNotContainsString($plaintext, $first);
        self::assertSame($plaintext, $this->cipher->decrypt($first, $this->key, $this->context));
        self::assertSame($plaintext, $this->cipher->decrypt($second, $this->key, $this->context));
    }

    public function testTamperAndWrongKeyFailClosed(): void
    {
        $envelope = $this->cipher->encrypt('synthetic-secret', $this->key, $this->context);
        $decoded = json_decode($envelope, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertIsString($decoded['ciphertext']);
        $decoded['ciphertext'][0] = 'A' === $decoded['ciphertext'][0] ? 'B' : 'A';
        $tampered = json_encode($decoded, JSON_THROW_ON_ERROR);

        foreach ([$tampered, $envelope] as $index => $candidate) {
            try {
                $this->cipher->decrypt($candidate, 0 === $index ? $this->key : random_bytes(32), $this->context);
                self::fail('Authentication failure was not rejected.');
            } catch (\RuntimeException $exception) {
                self::assertSame('Encrypted secret authentication failed.', $exception->getMessage());
                self::assertStringNotContainsString('synthetic-secret', $exception->getMessage());
            }
        }
    }

    public function testEveryAssociatedDataIdentityDimensionIsBound(): void
    {
        $envelope = $this->cipher->encrypt('synthetic-secret', $this->key, $this->context);
        $wrongContexts = [
            new SecretContext('018f6f4b-03d2-7aaa-8aaa-000000000099', $this->context->recordType, $this->context->recordId),
            new SecretContext($this->context->installationId, 'identity.totp-seed', $this->context->recordId),
            new SecretContext($this->context->installationId, $this->context->recordType, '018f6f4b-03d2-7bbb-8bbb-000000000099'),
            new SecretContext($this->context->installationId, $this->context->recordType, $this->context->recordId, 2),
        ];

        foreach ($wrongContexts as $wrongContext) {
            $this->expectAuthenticationFailure($envelope, $wrongContext);
        }
    }

    public function testUnsupportedEnvelopeAndKeyVersionsFailClosed(): void
    {
        $decoded = json_decode($this->cipher->encrypt('synthetic-secret', $this->key, $this->context), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        foreach (['envelope_version', 'key_version'] as $field) {
            $modified = $decoded;
            $modified[$field] = 99;
            $this->expectAuthenticationFailure(json_encode($modified, JSON_THROW_ON_ERROR), $this->context);
        }
    }

    private function expectAuthenticationFailure(string $envelope, SecretContext $context): void
    {
        try {
            $this->cipher->decrypt($envelope, $this->key, $context);
            self::fail('Authentication failure was not rejected.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Encrypted secret authentication failed.', $exception->getMessage());
        }
    }
}
