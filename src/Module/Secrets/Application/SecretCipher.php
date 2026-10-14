<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application;

final class SecretCipher
{
    public const ENVELOPE_VERSION = 1;
    public const KEY_VERSION = 1;

    public function encrypt(string $plaintext, string $masterKey, SecretContext $context): string
    {
        $this->assertMasterKey($masterKey);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $context->associatedData(),
            $nonce,
            $this->deriveKey($masterKey, $context->recordType, self::KEY_VERSION),
        );

        return json_encode([
            'envelope_version' => self::ENVELOPE_VERSION,
            'key_version' => self::KEY_VERSION,
            'schema_version' => $context->schemaVersion,
            'nonce' => sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'ciphertext' => sodium_bin2base64($ciphertext, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function decrypt(string $envelope, string $masterKey, SecretContext $context): string
    {
        $this->assertMasterKey($masterKey);

        try {
            $decoded = json_decode($envelope, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Encrypted secret authentication failed.', previous: $exception);
        }

        if (!\is_array($decoded)
            || self::ENVELOPE_VERSION !== ($decoded['envelope_version'] ?? null)
            || self::KEY_VERSION !== ($decoded['key_version'] ?? null)
            || $context->schemaVersion !== ($decoded['schema_version'] ?? null)
            || !\is_string($decoded['nonce'] ?? null)
            || !\is_string($decoded['ciphertext'] ?? null)
        ) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }

        try {
            $nonce = sodium_base642bin($decoded['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $ciphertext = sodium_base642bin($decoded['ciphertext'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException $exception) {
            throw new \RuntimeException('Encrypted secret authentication failed.', previous: $exception);
        }

        if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== \strlen($nonce)) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $context->associatedData(),
            $nonce,
            $this->deriveKey($masterKey, $context->recordType, self::KEY_VERSION),
        );

        if (false === $plaintext) {
            throw new \RuntimeException('Encrypted secret authentication failed.');
        }

        return $plaintext;
    }

    private function deriveKey(string $masterKey, string $recordType, int $keyVersion): string
    {
        $context = \sprintf('emailer-light/encrypted-record/%s/v%d', $recordType, $keyVersion);

        return hash_hkdf('sha256', $masterKey, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, $context, 'emailer-light');
    }

    private function assertMasterKey(string $masterKey): void
    {
        if (SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES !== \strlen($masterKey)) {
            throw new \RuntimeException('Master key is unavailable or malformed.');
        }
    }
}
