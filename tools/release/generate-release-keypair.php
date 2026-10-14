<?php

declare(strict_types=1);

if (3 !== $argc) {
    fwrite(STDERR, "Usage: generate-release-keypair.php PRIVATE PUBLIC\n");
    exit(2);
}

[$script, $privateFile, $publicFile] = $argv;
$project = realpath(dirname(__DIR__, 2));
$privateParent = realpath(dirname($privateFile));
if (false === $project || false === $privateParent || str_starts_with($privateParent.'/', $project.'/') || $privateParent === $project || (fileperms($privateParent) & 0o077) !== 0) {
    fwrite(STDERR, "Private release key requires a protected directory outside the project.\n");
    exit(2);
}
unset($script);
foreach ([$privateFile, $publicFile] as $path) {
    if (file_exists($path) || is_link($path)) {
        fwrite(STDERR, "Refusing to replace key material.\n");
        exit(2);
    }
}

$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);
try {
    foreach ([[$privateFile, $secret, 0o600], [$publicFile, $public, 0o644]] as [$path, $value, $mode]) {
        $handle = @fopen($path, 'xb');
        if (false === $handle || !chmod($path, $mode)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Release key material cannot be written.');
        }
        try {
            $encoded = sodium_bin2base64($value, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
            $offset = 0;
            while ($offset < strlen($encoded)) {
                $written = fwrite($handle, substr($encoded, $offset));
                if (false === $written || 0 === $written) {
                    throw new RuntimeException('Release key material cannot be written.');
                }
                $offset += $written;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Release key material cannot be written.');
            }
        } finally {
            fclose($handle);
        }
    }
} catch (Throwable) {
    @unlink($privateFile);
    @unlink($publicFile);
    sodium_memzero($secret);
    fwrite(STDERR, "Release key generation failed.\n");
    exit(2);
}
sodium_memzero($secret);
