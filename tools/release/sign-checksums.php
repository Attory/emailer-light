<?php

declare(strict_types=1);

if (4 !== $argc) {
    throw new RuntimeException('Expected an external signing-key path, checksum file and new signature path.');
}
$stat = lstat($argv[1]);
if (!is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077) || $stat['size'] > 256 || file_exists($argv[3])) {
    throw new RuntimeException('Signing-key custody or signature destination is unsafe.');
}
$key = sodium_base642bin(trim((string) file_get_contents($argv[1])), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
try {
    if (SODIUM_CRYPTO_SIGN_SECRETKEYBYTES !== strlen($key)) {
        throw new RuntimeException('The existing signing key has an invalid format.');
    }
    $bytes = file_get_contents($argv[2]);
    if (false === $bytes || false === file_put_contents($argv[3], sodium_bin2base64(sodium_crypto_sign_detached($bytes, $key), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n")) {
        throw new RuntimeException('Checksum signature could not be created.');
    }
    if (!sodium_crypto_sign_verify_detached(sodium_base642bin(trim((string) file_get_contents($argv[3])), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING), $bytes, sodium_crypto_sign_publickey_from_secretkey($key))) {
        throw new RuntimeException('Checksum signature self-verification failed.');
    }
} finally {
    sodium_memzero($key);
}
