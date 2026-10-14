<?php

declare(strict_types=1);

namespace App\Module\Secrets\Infrastructure;

use App\Module\Secrets\Application\Contract\DirectoryDurability;
use App\Module\Secrets\Application\Contract\FileDurability;
use App\Module\Secrets\Application\Contract\MasterKey;

final readonly class FilesystemMasterKey implements MasterKey
{
    private const DIRECTORY_TYPE = 0o040000;
    private const REGULAR_TYPE = 0o100000;
    private const TYPE_MASK = 0o170000;

    public function __construct(
        private string $keyFile,
        private string $publicDirectory,
        private string $projectDirectory,
        private string $environment,
        private ?DirectoryDurability $directoryDurability = null,
        private ?FileDurability $fileDurability = null,
    ) {
    }

    public function create(): void
    {
        $keyFile = $this->validatedKeyPath();
        if ($this->pathExists($keyFile)) {
            $this->load();

            return;
        }

        $directory = \dirname($keyFile);
        $this->createMissingDirectories($directory);
        $this->assertWritableKeyDirectory($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $temporaryFile = $directory.'/.master-key-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64(random_bytes(32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        $temporaryMetadata = null;

        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master key cannot be created safely.');
            }

            try {
                if (!@chmod($temporaryFile, 0o600) || \strlen($encoded) !== fwrite($handle, $encoded) || !fflush($handle)) {
                    throw new \RuntimeException('Master key cannot be written safely.');
                }
                $this->syncFile($handle);
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Master key has unsafe filesystem metadata.');
                }
                $temporaryMetadata = $metadata;
            } finally {
                fclose($handle);
            }

            $this->assertSnapshotUnchanged($ancestors);
            $published = @link($temporaryFile, $keyFile);
            if (!$published && !$this->pathExists($keyFile)) {
                throw new \RuntimeException('Master key cannot be published safely.');
            }
            if ($published && !@unlink($temporaryFile)) {
                @unlink($keyFile);
                throw new \RuntimeException('Master-key staging cannot be removed safely.');
            }
            $this->syncDirectory($directory);
            try {
                $this->assertSnapshotUnchanged($ancestors);
            } catch (\Throwable $exception) {
                $publishedMetadata = @lstat($keyFile);
                if ($published && \is_array($publishedMetadata) && $this->sameObject($temporaryMetadata, $publishedMetadata)) {
                    @unlink($keyFile);
                }
                throw $exception;
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }

        $this->load();
    }

    public function load(): string
    {
        $keyFile = $this->validatedKeyPath();
        $ancestors = $this->validateAncestorChain(\dirname($keyFile), true);
        $before = $this->lstat($keyFile);

        if (!$this->isSafeKeyMetadata($before)) {
            throw new \RuntimeException('Master key is missing or has unsafe filesystem metadata.');
        }

        $handle = @fopen($keyFile, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Master key cannot be opened safely.');
        }

        try {
            $openedBefore = fstat($handle);
            if (false === $openedBefore
                || !$this->isSafeKeyMetadata($openedBefore)
                || !$this->sameObject($before, $openedBefore)
                || $openedBefore['size'] > 128
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }

            $contents = stream_get_contents($handle, 129);
            $openedAfter = fstat($handle);
            $after = $this->lstat($keyFile);
            if (false === $contents
                || false === $openedAfter
                || \strlen($contents) > 128
                || !$this->sameObject($openedBefore, $openedAfter)
                || !$this->sameObject($before, $after)
            ) {
                throw new \RuntimeException('Master key changed during validation.');
            }
            $this->assertSnapshotUnchanged($ancestors);
        } finally {
            fclose($handle);
        }

        try {
            $key = sodium_base642bin(rtrim($contents, "\r\n"), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException $exception) {
            throw new \RuntimeException('Master key is malformed.', previous: $exception);
        } finally {
            sodium_memzero($contents);
        }

        if (32 !== \strlen($key)) {
            sodium_memzero($key);
            throw new \RuntimeException('Master key is malformed.');
        }

        return $key;
    }

    public function exists(): bool
    {
        return $this->pathExists($this->validatedKeyPath());
    }

    public function recover(string $key): void
    {
        if (32 !== \strlen($key)) {
            throw new \InvalidArgumentException('Recovered master key is malformed.');
        }
        $keyFile = $this->validatedKeyPath();
        if ($this->pathExists($keyFile)) {
            throw new \RuntimeException('Master key already exists; recovery will not replace it.');
        }
        $directory = \dirname($keyFile);
        $this->createMissingDirectories($directory);
        $this->assertWritableKeyDirectory($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $temporaryFile = $directory.'/.master-key-recovery-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Recovered master key cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600) || \strlen($encoded) !== fwrite($handle, $encoded) || !fflush($handle)) {
                    throw new \RuntimeException('Recovered master key cannot be written safely.');
                }
                $this->syncFile($handle);
            } finally {
                fclose($handle);
            }
            $this->assertSnapshotUnchanged($ancestors);
            if (!@link($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Recovered master key cannot be published safely.');
            }
            try {
                $this->assertSnapshotUnchanged($ancestors);
            } catch (\Throwable $exception) {
                @unlink($keyFile);
                throw $exception;
            }
            if (!@unlink($temporaryFile)) {
                @unlink($keyFile);
                throw new \RuntimeException('Recovered master key staging cannot be removed safely.');
            }
            $this->syncDirectory($directory);
            $publishedMetadata = @lstat($keyFile);
            try {
                $loaded = $this->load();
                try {
                    if (!hash_equals($key, $loaded)) {
                        throw new \RuntimeException('Recovered master key verification failed.');
                    }
                } finally {
                    sodium_memzero($loaded);
                }
            } catch (\Throwable $exception) {
                $currentMetadata = @lstat($keyFile);
                if (\is_array($publishedMetadata) && \is_array($currentMetadata) && $this->sameObject($publishedMetadata, $currentMetadata)) {
                    @unlink($keyFile);
                }
                throw $exception;
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    public function replace(string $expectedCurrentKey, string $replacementKey): void
    {
        if (32 !== \strlen($expectedCurrentKey) || 32 !== \strlen($replacementKey)) {
            throw new \InvalidArgumentException('Master-key replacement material is malformed.');
        }

        $current = $this->load();
        try {
            if (!hash_equals($expectedCurrentKey, $current)) {
                throw new \RuntimeException('Master key changed before replacement.');
            }
        } finally {
            sodium_memzero($current);
        }

        $keyFile = $this->validatedKeyPath();
        $directory = \dirname($keyFile);
        $this->assertWritableKeyDirectory($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $before = $this->lstat($keyFile);
        if (!$this->isSafeKeyMetadata($before)) {
            throw new \RuntimeException('Master key has unsafe filesystem metadata.');
        }

        $temporaryFile = $directory.'/.master-key-replacement-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($replacementKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        $published = false;
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Replacement master key cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600)
                    || \strlen($encoded) !== fwrite($handle, $encoded)
                    || !fflush($handle)
                ) {
                    throw new \RuntimeException('Replacement master key cannot be written safely.');
                }
                $this->syncFile($handle);
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Replacement master key has unsafe filesystem metadata.');
                }
            } finally {
                fclose($handle);
            }

            $this->assertSnapshotUnchanged($ancestors);
            $currentMetadata = $this->lstat($keyFile);
            if (!$this->sameObject($before, $currentMetadata)
                || $before['mode'] !== $currentMetadata['mode']
                || $before['uid'] !== $currentMetadata['uid']
            ) {
                throw new \RuntimeException('Master key changed during replacement.');
            }
            if (!@rename($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Replacement master key cannot be published atomically.');
            }
            $published = true;
            $this->syncDirectory($directory);
            $this->assertSnapshotUnchanged($ancestors);
            $loaded = $this->load();
            try {
                if (!hash_equals($replacementKey, $loaded)) {
                    throw new \RuntimeException('Replacement master-key verification failed.');
                }
            } finally {
                sodium_memzero($loaded);
            }
        } catch (\Throwable $exception) {
            if ($published) {
                try {
                    $this->restoreFailedReplacement($keyFile, $expectedCurrentKey, $ancestors);
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException('Master-key replacement failed and automatic filesystem rollback failed.', previous: $rollbackFailure);
                }
            }
            throw $exception;
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    public function prepareRotation(string $expectedCurrentKey, string $replacementKey, string $operationId): void
    {
        if (32 !== \strlen($expectedCurrentKey)
            || 32 !== \strlen($replacementKey)
            || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $operationId)
        ) {
            throw new \InvalidArgumentException('Master-key rotation journal material is malformed.');
        }
        $current = $this->load();
        try {
            if (!hash_equals($expectedCurrentKey, $current)) {
                throw new \RuntimeException('Master key changed before rotation journaling.');
            }
        } finally {
            sodium_memzero($current);
        }

        $journal = $this->rotationJournalPath();
        if ($this->pathExists($journal)) {
            throw new \RuntimeException('A master-key rotation journal already requires recovery.');
        }
        $directory = \dirname($journal);
        $this->assertWritableKeyDirectory($directory);
        $ancestors = $this->validateAncestorChain($directory, true);
        $temporary = $directory.'/.master-key-rotation-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = json_encode([
            'format' => 'emailer-light-master-key-rotation-v1',
            'operation_id' => $operationId,
            'old_key' => sodium_bin2base64($expectedCurrentKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
            'new_key' => sodium_bin2base64($replacementKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        try {
            $handle = @fopen($temporary, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master-key rotation journal cannot be staged.');
            }
            try {
                if (!@chmod($temporary, 0o600)
                    || \strlen($encoded) !== fwrite($handle, $encoded)
                    || !fflush($handle)
                ) {
                    throw new \RuntimeException('Master-key rotation journal cannot be persisted.');
                }
                $this->syncFile($handle);
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Master-key rotation journal metadata is unsafe.');
                }
            } finally {
                fclose($handle);
            }
            $this->assertSnapshotUnchanged($ancestors);
            if (!@link($temporary, $journal)) {
                throw new \RuntimeException('Master-key rotation journal cannot be published exclusively.');
            }
            if (!@unlink($temporary)) {
                @unlink($journal);
                throw new \RuntimeException('Master-key rotation journal staging cannot be removed.');
            }
            $this->syncDirectory($directory);
            $verified = $this->readRotationJournal();
            sodium_memzero($verified['old_key']);
            sodium_memzero($verified['new_key']);
        } finally {
            @unlink($temporary);
            sodium_memzero($encoded);
        }
    }

    public function pendingRotationId(): ?string
    {
        if (!$this->pathExists($this->rotationJournalPath())) {
            return null;
        }
        $journal = $this->readRotationJournal();
        try {
            return $journal['operation_id'];
        } finally {
            sodium_memzero($journal['old_key']);
            sodium_memzero($journal['new_key']);
        }
    }

    public function publishRotation(string $operationId): void
    {
        $journal = $this->readRotationJournal();
        try {
            if (!hash_equals($journal['operation_id'], $operationId)) {
                throw new \RuntimeException('Master-key rotation journal identity changed.');
            }
            $current = $this->load();
            try {
                if (hash_equals($journal['new_key'], $current)) {
                    return;
                }
                if (!hash_equals($journal['old_key'], $current)) {
                    throw new \RuntimeException('Master key matches neither side of the rotation journal.');
                }
            } finally {
                sodium_memzero($current);
            }
            $this->replace($journal['old_key'], $journal['new_key']);
        } finally {
            sodium_memzero($journal['old_key']);
            sodium_memzero($journal['new_key']);
        }
    }

    public function resolveRotation(string $operationId, bool $committed): void
    {
        $journalPath = $this->rotationJournalPath();
        $this->assertWritableKeyDirectory(\dirname($journalPath));
        $journalMetadata = $this->lstat($journalPath);
        $journal = $this->readRotationJournal();
        try {
            if (!hash_equals($journal['operation_id'], $operationId)) {
                throw new \RuntimeException('Master-key rotation journal identity changed.');
            }
            $desired = $committed ? $journal['new_key'] : $journal['old_key'];
            $alternate = $committed ? $journal['old_key'] : $journal['new_key'];
            $current = $this->load();
            try {
                if (!hash_equals($desired, $current)) {
                    if (!hash_equals($alternate, $current)) {
                        throw new \RuntimeException('Master key matches neither side of the rotation journal.');
                    }
                    $this->replace($current, $desired);
                }
            } finally {
                sodium_memzero($current);
            }
            $currentMetadata = $this->lstat($journalPath);
            if (!$this->sameObject($journalMetadata, $currentMetadata) || !@unlink($journalPath) || $this->pathExists($journalPath)) {
                throw new \RuntimeException('Resolved master-key rotation journal cannot be removed safely.');
            }
            $this->syncDirectory(\dirname($journalPath));
        } finally {
            sodium_memzero($journal['old_key']);
            sodium_memzero($journal['new_key']);
        }
    }

    /** @return array{operation_id: string, old_key: string, new_key: string} */
    private function readRotationJournal(): array
    {
        $journal = $this->rotationJournalPath();
        $ancestors = $this->validateAncestorChain(\dirname($journal), true);
        $before = $this->lstat($journal);
        if (!$this->isSafeKeyMetadata($before) || $before['size'] > 1024) {
            throw new \RuntimeException('Master-key rotation journal metadata is unsafe.');
        }
        $handle = @fopen($journal, 'rb');
        if (false === $handle) {
            throw new \RuntimeException('Master-key rotation journal cannot be opened.');
        }
        try {
            $opened = fstat($handle);
            $contents = stream_get_contents($handle, 1025);
            $after = $this->lstat($journal);
            if (false === $opened
                || false === $contents
                || !$this->sameObject($before, $opened)
                || !$this->sameObject($opened, $after)
                || \strlen($contents) > 1024
            ) {
                throw new \RuntimeException('Master-key rotation journal changed while being read.');
            }
            $this->assertSnapshotUnchanged($ancestors);
        } finally {
            fclose($handle);
        }
        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } finally {
            sodium_memzero($contents);
        }
        if (!\is_array($decoded)
            || 'emailer-light-master-key-rotation-v1' !== ($decoded['format'] ?? null)
            || !\is_string($decoded['operation_id'] ?? null)
            || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $decoded['operation_id'])
            || !\is_string($decoded['old_key'] ?? null)
            || !\is_string($decoded['new_key'] ?? null)
        ) {
            if (\is_array($decoded)) {
                if (\is_string($decoded['old_key'] ?? null)) {
                    sodium_memzero($decoded['old_key']);
                }
                if (\is_string($decoded['new_key'] ?? null)) {
                    sodium_memzero($decoded['new_key']);
                }
            }
            throw new \RuntimeException('Master-key rotation journal is malformed.');
        }
        $oldKey = null;
        $newKey = null;
        try {
            $oldKey = sodium_base642bin($decoded['old_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
            $newKey = sodium_base642bin($decoded['new_key'], SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING, '');
        } catch (\SodiumException $exception) {
            if (\is_string($oldKey)) {
                sodium_memzero($oldKey);
            }
            throw new \RuntimeException('Master-key rotation journal is malformed.', previous: $exception);
        } finally {
            sodium_memzero($decoded['old_key']);
            sodium_memzero($decoded['new_key']);
        }
        if (32 !== \strlen($oldKey) || 32 !== \strlen($newKey)) {
            sodium_memzero($oldKey);
            sodium_memzero($newKey);
            throw new \RuntimeException('Master-key rotation journal is malformed.');
        }

        return ['operation_id' => $decoded['operation_id'], 'old_key' => $oldKey, 'new_key' => $newKey];
    }

    private function rotationJournalPath(): string
    {
        return $this->validatedKeyPath().'.rotation-journal';
    }

    /** @param array<string, array<mixed>> $ancestors */
    private function restoreFailedReplacement(string $keyFile, string $key, array $ancestors): void
    {
        $directory = \dirname($keyFile);
        $temporaryFile = $directory.'/.master-key-rollback-'.bin2hex(random_bytes(12)).'.tmp';
        $encoded = sodium_bin2base64($key, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n";
        try {
            $handle = @fopen($temporaryFile, 'x+b');
            if (false === $handle) {
                throw new \RuntimeException('Master-key rollback cannot be staged safely.');
            }
            try {
                if (!@chmod($temporaryFile, 0o600)
                    || \strlen($encoded) !== fwrite($handle, $encoded)
                    || !fflush($handle)
                ) {
                    throw new \RuntimeException('Master-key rollback cannot be written safely.');
                }
                $this->syncFile($handle);
                $metadata = fstat($handle);
                if (false === $metadata || !$this->isSafeKeyMetadata($metadata)) {
                    throw new \RuntimeException('Master-key rollback has unsafe filesystem metadata.');
                }
            } finally {
                fclose($handle);
            }
            $this->assertSnapshotUnchanged($ancestors);
            if (!@rename($temporaryFile, $keyFile)) {
                throw new \RuntimeException('Master-key rollback cannot be published atomically.');
            }
            $this->syncDirectory($directory);
            $loaded = $this->load();
            try {
                if (!hash_equals($key, $loaded)) {
                    throw new \RuntimeException('Master-key rollback verification failed.');
                }
            } finally {
                sodium_memzero($loaded);
            }
        } finally {
            @unlink($temporaryFile);
            sodium_memzero($encoded);
        }
    }

    private function validatedKeyPath(): string
    {
        if ('' === $this->keyFile || !str_starts_with($this->keyFile, '/') || str_contains($this->keyFile, "\0")) {
            throw new \RuntimeException('Master-key configuration must be an explicit absolute path.');
        }

        $key = $this->normalizeAbsolutePath($this->keyFile);
        if ('/' === $key) {
            throw new \RuntimeException('Master-key configuration is invalid.');
        }

        if ($this->isWithin($key, $this->normalizeAbsolutePath($this->publicDirectory))) {
            throw new \RuntimeException('Master key must remain outside the public directory.');
        }

        if (!\in_array($this->environment, ['dev', 'test'], true)
            && $this->isWithin($key, $this->normalizeAbsolutePath($this->projectDirectory))
        ) {
            throw new \RuntimeException('Production master key must remain outside the application release tree.');
        }

        return $key;
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if (!str_starts_with($path, '/')) {
            throw new \RuntimeException('Master-key configuration must be an explicit absolute path.');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ('' === $segment || '.' === $segment) {
                continue;
            }
            if ('..' === $segment) {
                throw new \RuntimeException('Master-key configuration must not contain parent traversal.');
            }
            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    private function isWithin(string $candidate, string $directory): bool
    {
        return $candidate === $directory || str_starts_with($candidate.'/', rtrim($directory, '/').'/');
    }

    private function createMissingDirectories(string $directory): void
    {
        $current = '';
        foreach (explode('/', ltrim($directory, '/')) as $segment) {
            $current .= '/'.$segment;
            if ($this->pathExists($current)) {
                $this->validateDirectory($current, $current === $directory);

                continue;
            }
            if (!@mkdir($current, 0o700) || !@chmod($current, 0o700)) {
                throw new \RuntimeException('Master-key directory cannot be created safely.');
            }
            $this->validateDirectory($current, true);
        }
    }

    /** @return array<string, array<mixed>> */
    private function validateAncestorChain(string $directory, bool $strictParent): array
    {
        $snapshot = [];
        $current = '/';
        $root = $this->lstat($current);
        $this->assertSafeAncestor($root, false);
        $snapshot[$current] = $root;

        foreach (explode('/', ltrim($directory, '/')) as $segment) {
            $current = '/' === $current ? '/'.$segment : $current.'/'.$segment;
            $metadata = $this->lstat($current);
            $this->assertSafeAncestor($metadata, $strictParent && $current === $directory);
            $snapshot[$current] = $metadata;
        }

        return $snapshot;
    }

    private function validateDirectory(string $directory, bool $strict): void
    {
        $this->assertSafeAncestor($this->lstat($directory), $strict);
    }

    /** @param array<mixed> $metadata */
    private function assertSafeAncestor(array $metadata, bool $strict): void
    {
        $mode = $metadata['mode'] ?? null;
        $owner = $metadata['uid'] ?? null;
        if (!\is_int($mode) || !\is_int($owner)) {
            throw new \RuntimeException('Master-key ancestor metadata is unsafe.');
        }
        if (self::DIRECTORY_TYPE !== ($mode & self::TYPE_MASK)) {
            throw new \RuntimeException('Master-key ancestor is not a real directory.');
        }

        $effectiveUser = $this->effectiveUserId();
        if (!\in_array($owner, [0, $effectiveUser], true) && !(0 === $effectiveUser && $strict)) {
            throw new \RuntimeException('Master-key ancestor ownership is unsafe.');
        }

        $permissions = $mode & 0o7777;
        if ($strict) {
            if ((0 !== $effectiveUser && $effectiveUser !== $owner) || 0o700 !== ($permissions & 0o777)) {
                throw new \RuntimeException('Master-key directory permissions or ownership are unsafe.');
            }

            return;
        }

        if (0 !== ($permissions & 0o022)
            && !(0 === $owner && 0 !== ($permissions & 0o1000))
        ) {
            throw new \RuntimeException('Master-key ancestor permissions are unsafe.');
        }
    }

    /** @param array<string, array<mixed>> $snapshot */
    private function assertSnapshotUnchanged(array $snapshot): void
    {
        foreach ($snapshot as $path => $before) {
            $after = $this->lstat($path);
            if (!$this->sameObject($before, $after) || $before['mode'] !== $after['mode'] || $before['uid'] !== $after['uid']) {
                throw new \RuntimeException('Master-key ancestor changed during validation.');
            }
        }
    }

    /** @param array<mixed> $metadata */
    private function isSafeKeyMetadata(array $metadata): bool
    {
        $mode = $metadata['mode'] ?? null;
        $owner = $metadata['uid'] ?? null;
        $effectiveUser = $this->effectiveUserId();
        $parent = @lstat(\dirname($this->validatedKeyPath()));
        $rootReadingServiceKey = 0 === $effectiveUser
            && \is_array($parent)
            && 0o040000 === ($parent['mode'] & self::TYPE_MASK)
            && 0o700 === ($parent['mode'] & 0o777)
            && $owner === $parent['uid'];

        return \is_int($mode)
            && self::REGULAR_TYPE === ($mode & self::TYPE_MASK)
            && ($effectiveUser === $owner || $rootReadingServiceKey)
            && 1 === ($metadata['nlink'] ?? null)
            && 0o600 === ($mode & 0o777);
    }

    private function assertWritableKeyDirectory(string $directory): void
    {
        $metadata = $this->lstat($directory);
        if (($metadata['uid'] ?? null) !== $this->effectiveUserId()) {
            throw new \RuntimeException('Master-key mutation requires the service-owned key directory identity.');
        }
    }

    private function syncDirectory(string $directory): void
    {
        ($this->directoryDurability ?? new PosixDirectoryDurability())->sync($directory);
    }

    /** @param resource $handle */
    private function syncFile(mixed $handle): void
    {
        ($this->fileDurability ?? new PosixFileDurability())->sync($handle);
    }

    /**
     * @param array<mixed> $left
     * @param array<mixed> $right
     */
    private function sameObject(array $left, array $right): bool
    {
        return $left['ino'] === $right['ino'] && $left['dev'] === $right['dev'];
    }

    /** @return array<mixed> */
    private function lstat(string $path): array
    {
        clearstatcache(true, $path);
        $metadata = @lstat($path);
        if (false === $metadata) {
            throw new \RuntimeException('Master-key filesystem path is missing or unsafe.');
        }

        return $metadata;
    }

    private function pathExists(string $path): bool
    {
        clearstatcache(true, $path);

        return false !== @lstat($path);
    }

    private function effectiveUserId(): int
    {
        if (!\function_exists('posix_geteuid')) {
            throw new \RuntimeException('Master-key ownership cannot be validated on this runtime.');
        }

        return posix_geteuid();
    }
}
