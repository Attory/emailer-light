<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application;

use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Secrets\Application\Contract\RecoveryKeyVerifier;

final readonly class RecoveryKeyExporter implements RecoveryKeyVerifier
{
    public function __construct(private MasterKey $masterKey, private string $outputFile)
    {
    }

    public function export(string $passphrase): string
    {
        if (16 > \strlen($passphrase)) {
            throw new \InvalidArgumentException('Recovery passphrase must contain at least 16 characters.');
        }
        if ('' === $this->outputFile || !str_starts_with($this->outputFile, '/') || str_contains($this->outputFile, '/public/')) {
            throw new \RuntimeException('Recovery-key package path is unsafe.');
        }
        $directory = \dirname($this->outputFile);
        if (!is_dir($directory) && (!@mkdir($directory, 0o700, true) || !@chmod($directory, 0o700))) {
            throw new \RuntimeException('Recovery-key package directory cannot be created safely.');
        }
        $directoryStat = @lstat($directory);
        if (!\is_array($directoryStat) || 0o040000 !== ($directoryStat['mode'] & 0o170000) || is_link($directory) || file_exists($this->outputFile) || is_link($this->outputFile)) {
            throw new \RuntimeException('Recovery-key package destination is unsafe or already exists.');
        }
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(32, $passphrase, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $master = $this->masterKey->load();
        try {
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($master, 'emailer-light/recovery-key/v1', $nonce, $key);
            $envelope = ['format' => 'emailer-light-recovery-key-v1', 'kdf' => 'argon2id13', 'opslimit' => 3, 'memlimit' => 67108864, 'salt' => sodium_bin2base64($salt, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'nonce' => sodium_bin2base64($nonce, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), 'ciphertext' => sodium_bin2base64($cipher, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)];
            $temporary = $directory.'/.recovery-'.bin2hex(random_bytes(8)).'.tmp';
            if (false === @file_put_contents($temporary, json_encode($envelope, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n", LOCK_EX) || !@chmod($temporary, 0o600)) {
                @unlink($temporary);
                throw new \RuntimeException('Recovery-key package cannot be published safely.');
            }
            $handle = @fopen($temporary, 'rb');
            if (false === $handle) {
                @unlink($temporary);
                throw new \RuntimeException('Recovery-key package cannot be synchronized.');
            }
            try {
                if (!fsync($handle)) {
                    throw new \RuntimeException('Recovery-key package durability failed.');
                }
            } finally {
                fclose($handle);
            }
            if (!@link($temporary, $this->outputFile)) {
                @unlink($temporary);
                throw new \RuntimeException('Recovery-key package already exists or cannot be published safely.');
            }
            if (!@unlink($temporary)) {
                @unlink($this->outputFile);
                @unlink($temporary);
                throw new \RuntimeException('Recovery-key package staging cannot be removed safely.');
            }
            $directoryHandle = @fopen($directory, 'rb');
            if (false === $directoryHandle) {
                throw new \RuntimeException('Recovery-key directory cannot be synchronized.');
            }
            try {
                if (!fsync($directoryHandle)) {
                    throw new \RuntimeException('Recovery-key directory durability failed.');
                }
            } finally {
                fclose($directoryHandle);
            }
            if (!$this->verifiesKey($passphrase, $master)) {
                throw new \RuntimeException('Recovery-key package verification failed.');
            }
        } finally {
            sodium_memzero($master);
            sodium_memzero($key);
        }

        return $this->outputFile;
    }

    public function recover(string $passphrase): void
    {
        $master = $this->decryptPackage($passphrase);
        try {
            $this->masterKey->recover($master);
        } finally {
            sodium_memzero($master);
        }
    }

    public function verifiesKey(string $passphrase, string $expectedKey): bool
    {
        if (32 !== \strlen($expectedKey)) {
            return false;
        }
        try {
            $recovered = $this->decryptPackage($passphrase);
            try {
                return hash_equals($expectedKey, $recovered);
            } finally {
                sodium_memzero($recovered);
            }
        } catch (\Throwable) {
            return false;
        }
    }

    private function decryptPackage(string $passphrase): string
    {
        $stat = @lstat($this->outputFile);
        if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077) || $stat['size'] > 4096) {
            throw new \RuntimeException('Recovery-key package is missing or unsafe.');
        }
        $contents = file_get_contents($this->outputFile);
        if (false === $contents) {
            throw new \RuntimeException('Recovery-key package cannot be read.');
        }
        $envelope = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        if (!\is_array($envelope) || 'emailer-light-recovery-key-v1' !== ($envelope['format'] ?? null) || 'argon2id13' !== ($envelope['kdf'] ?? null) || 3 !== ($envelope['opslimit'] ?? null) || 67108864 !== ($envelope['memlimit'] ?? null)) {
            throw new \RuntimeException('Recovery-key package is incompatible.');
        }
        foreach (['salt', 'nonce', 'ciphertext'] as $field) {
            if (!\is_string($envelope[$field] ?? null)) {
                throw new \RuntimeException('Recovery-key package is malformed.');
            }
        }
        $salt = sodium_base642bin($envelope['salt'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $nonce = sodium_base642bin($envelope['nonce'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        $cipher = sodium_base642bin($envelope['ciphertext'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        if (SODIUM_CRYPTO_PWHASH_SALTBYTES !== \strlen($salt) || SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== \strlen($nonce)) {
            throw new \RuntimeException('Recovery-key package is malformed.');
        }
        $key = sodium_crypto_pwhash(32, $passphrase, $salt, 3, 67108864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
        try {
            $master = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, 'emailer-light/recovery-key/v1', $nonce, $key);
            if (false === $master || 32 !== \strlen($master)) {
                throw new \RuntimeException('Recovery-key authentication failed.');
            }

            return $master;
        } finally {
            sodium_memzero($key);
        }
    }
}
