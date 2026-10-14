<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\BackupValidator;

final readonly class RecentBackupValidator implements BackupValidator
{
    private const MAX_AGE_SECONDS = 86400;
    private const MAX_EVIDENCE_SIZE = 65536;
    private const EVIDENCE_FORMAT = 'emailer-light-backup-evidence-v1';

    public function __construct(private string $publicDirectory)
    {
    }

    public function isValid(string $backupPath, int $now): bool
    {
        $metadata = self::lstatOrNull($backupPath);

        if (
            null === $metadata
            || 'file' !== @filetype($backupPath)
            || $metadata['size'] < 1
            || $metadata['size'] > self::MAX_EVIDENCE_SIZE
            || 1 !== $metadata['nlink']
            || 0 !== ($metadata['mode'] & 0o022)
            || $metadata['mtime'] > $now
            || $metadata['mtime'] < $now - self::MAX_AGE_SECONDS
        ) {
            return false;
        }

        $resolvedBackup = realpath($backupPath);
        $resolvedPublic = realpath($this->publicDirectory);

        if (
            false === $resolvedBackup
            || false === $resolvedPublic
            || str_starts_with(
                $resolvedBackup.DIRECTORY_SEPARATOR,
                rtrim($resolvedPublic, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR,
            )
        ) {
            return false;
        }

        $handle = @fopen($backupPath, 'rb');

        if (false === $handle) {
            return false;
        }

        try {
            $openedMetadata = fstat($handle);

            if (
                false === $openedMetadata
                || 0o100000 !== ($openedMetadata['mode'] & 0o170000)
                || $openedMetadata['size'] !== $metadata['size']
                || 1 !== $openedMetadata['nlink']
                || 0 !== ($openedMetadata['mode'] & 0o022)
                || $openedMetadata['dev'] !== $metadata['dev']
                || $openedMetadata['ino'] !== $metadata['ino']
            ) {
                return false;
            }

            $contents = stream_get_contents($handle, self::MAX_EVIDENCE_SIZE + 1);
            $afterReadMetadata = self::lstatOrNull($backupPath);
            $resolvedAfterRead = realpath($backupPath);

            if (
                false === $contents
                || '' === $contents
                || \strlen($contents) > self::MAX_EVIDENCE_SIZE
                || null === $afterReadMetadata
                || $afterReadMetadata['dev'] !== $openedMetadata['dev']
                || $afterReadMetadata['ino'] !== $openedMetadata['ino']
                || $afterReadMetadata['size'] !== $openedMetadata['size']
                || $afterReadMetadata['mtime'] !== $openedMetadata['mtime']
                || 1 !== $afterReadMetadata['nlink']
                || 0 !== ($afterReadMetadata['mode'] & 0o022)
                || $resolvedAfterRead !== $resolvedBackup
            ) {
                return false;
            }
        } finally {
            fclose($handle);
        }

        try {
            $evidence = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (
            !\is_array($evidence)
            || self::EVIDENCE_FORMAT !== ($evidence['format'] ?? null)
            || !\is_int($evidence['created_at'] ?? null)
            || $evidence['created_at'] > $now
            || $evidence['created_at'] < $now - self::MAX_AGE_SECONDS
            || !\is_string($evidence['payload'] ?? null)
            || !\is_string($evidence['sha256'] ?? null)
            || 1 !== preg_match('/^[a-f0-9]{64}$/', $evidence['sha256'])
        ) {
            return false;
        }

        $payload = base64_decode($evidence['payload'], true);

        if (
            false === $payload
            || '' === $payload
            || !hash_equals($evidence['sha256'], hash('sha256', $payload))
        ) {
            return false;
        }

        // Phase 2 has no trusted backup producer or authentication key. A
        // self-declared digest detects corruption but cannot prove that the
        // payload is a meaningful Emailer backup, so reset remains fail-closed.
        return false;
    }

    /** @return array<string|int, int>|null */
    private static function lstatOrNull(string $path): ?array
    {
        $metadata = @lstat($path);

        return false === $metadata ? null : $metadata;
    }
}
