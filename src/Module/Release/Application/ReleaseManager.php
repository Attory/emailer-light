<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Delivery\Application\Contract\InterruptedDeliveryRecovery;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class ReleaseManager
{
    public function __construct(private PackageInspector $inspector, private BackupOperations $backups, private Connection $connection, private LockFactory $locks, private MaintenanceOperationLock $maintenance, private InterruptedDeliveryRecovery $recovery, private string $releasesDirectory, private string $currentVersion, private int $rollbackReserveBytes = 536870912, private string $environment = 'test')
    {
    }

    /** @return array<string, mixed> */
    public function inspect(string $archive): array
    {
        return $this->inspector->inspect($archive);
    }

    public function install(string $archive, bool $switch = false): string
    {
        $manifest = $this->inspector->inspect($archive);
        $verifiedArchiveHash = $manifest['_verified_archive_sha256'] ?? null;
        if (!\is_string($verifiedArchiveHash)) {
            throw new \RuntimeException('Verified archive identity is unavailable.');
        }
        $version = $manifest['version'];
        if (!\is_string($version)) {
            throw new \RuntimeException('Release version is invalid.');
        }
        $files = $manifest['files'] ?? null;
        if (!\is_array($files)) {
            throw new \RuntimeException('Release file manifest is invalid.');
        }
        $files = $this->stringMap($files);
        $this->ensureReleaseRoot();
        $target = $this->releasesDirectory.'/'.$version;
        if (file_exists($target) || is_link($target)) {
            throw new \RuntimeException('The immutable release directory already exists.');
        }
        $staging = $this->releasesDirectory.'/.staging-'.Identity::uuidV7();
        $published = false;
        if (!mkdir($staging, 0o755)) {
            throw new \RuntimeException('Release staging cannot be created.');
        }
        try {
            $stagedArchive = $staging.'/.verified-package.zip';
            if (!copy($archive, $stagedArchive) || !chmod($stagedArchive, 0o600) || !hash_equals($verifiedArchiveHash, (string) hash_file('sha256', $stagedArchive))) {
                throw new \RuntimeException('Verified release cannot be staged without change.');
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($stagedArchive)) {
                throw new \RuntimeException('Verified release extraction failed.');
            }
            try {
                if (!$zip->extractTo($staging)) {
                    throw new \RuntimeException('Verified release extraction failed.');
                }
            } finally {
                $zip->close();
            }
            @unlink($stagedArchive);
            if (file_exists($stagedArchive) || is_link($stagedArchive)) {
                throw new \RuntimeException('Verified release staging cannot be removed.');
            }
            $this->assertExtracted($staging, $files);
            $this->freeze($staging);
            if (!rename($staging, $target)) {
                throw new \RuntimeException('Immutable release publication failed.');
            }
            $published = true;
            $this->assertPublishedOwnership($target);
            if ($switch) {
                $this->switchPointer($target);
            }

            return $target;
        } catch (\Throwable $exception) {
            $this->removeStaging($staging);
            if ($published) {
                $this->removeFailedRelease($target);
            }
            throw $exception;
        }
    }

    public function update(string $archive, string $sourceArchive): void
    {
        $this->maintenance->acquire();
        try {
            $this->updateWithLock($archive, $sourceArchive);
        } finally {
            $this->maintenance->release();
        }
    }

    private function updateWithLock(string $archive, string $sourceArchive): void
    {
        $lock = $this->locks->createLock('emailer-release-update', 900.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another release operation is active.');
        }
        $operationId = null;
        $safety = null;
        $previous = false;
        $pointerSwitched = false;
        $migrationApplied = false;
        $target = null;
        $manifest = [];
        $sourceSchemaFingerprint = null;
        try {
            $previous = realpath($this->releasesDirectory.'/current');
            if (false === $previous || !is_dir($previous) || !hash_equals($this->currentVersion, basename($previous))) {
                throw new \RuntimeException('Recorded update source does not match the immutable current release.');
            }
            $this->assertPublishedOwnership($previous);
            $sourceManifest = $this->inspector->inspectInstalledSource($sourceArchive);
            $this->assertInstalledReleaseMatchesManifest($previous, $sourceManifest);
            $manifest = $this->inspector->inspect($archive);
            $targetVersion = $manifest['version'] ?? null;
            if (!\is_string($targetVersion) || version_compare($targetVersion, $this->currentVersion, '<=')) {
                throw new \RuntimeException('Release downgrade or same-version update is not allowed.');
            }
            if ('prod' === $this->environment && '1.0.0' === $this->currentVersion && '1.1.0' === $targetVersion) {
                throw new \RuntimeException('Use the guided native installer for this schema-preserving Light update, including its persistent writer fencing and recovery journal.');
            }
            $this->assertPlatformAndSchemaCompatibility($manifest);
            $this->assertDiskCapacity($archive, $manifest);
            $sourceSchemaFingerprint = $this->schemaFingerprint();
            $operationId = Identity::uuidV7();
            $this->connection->insert('release_operation', ['id' => $operationId, 'operation' => 'update', 'source_version' => $this->currentVersion, 'target_version' => $manifest['version'], 'package_sha256' => hash_file('sha256', $archive), 'status' => 'started', 'detail_code' => 'verified', 'started_at' => Identity::now(), 'finished_at' => null]);
            $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, updated_at = ? WHERE singleton_key = '1'", [Identity::now()]);
            $this->recovery->recover();
            $deadline = microtime(true) + 30.0;
            while (false !== $this->connection->fetchOne("SELECT 1 FROM delivery_runtime WHERE singleton_key = '1' AND active_claims > 0")) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('Active delivery claims did not quiesce within the safe deadline.');
                }
                usleep(250000);
            }
            $safety = $this->backups->create();
            $this->transferSafetyBackupToServiceOwner($safety['path']);
            $target = $this->install($archive, false);
            $this->runConsole($target, ['doctrine:migrations:migrate', '--no-interaction'], [0]);
            $migrationApplied = true;
            $this->switchPointer($target);
            $pointerSwitched = true;
            $this->runConsole($target, ['emailer:health:verify-runtime', '--offline-maintenance'], [0]);
            $this->connection->update('release_operation', ['status' => 'succeeded', 'detail_code' => 'health_passed', 'finished_at' => Identity::now()], ['id' => $operationId]);
        } catch (\Throwable $exception) {
            if (null !== $safety) {
                try {
                    if ($pointerSwitched && $migrationApplied && true === ($manifest['rollback_schema_compatible'] ?? false) && false !== $previous) {
                        $this->switchPointer($previous);
                    } else {
                        $this->backups->restore($safety['path']);
                        if (null === $sourceSchemaFingerprint || !hash_equals($sourceSchemaFingerprint, $this->schemaFingerprint())) {
                            throw new \RuntimeException('Safety backup restored data but not the exact source schema; supervised schema recovery is required.');
                        }
                        if (false !== $previous) {
                            $this->switchPointer($previous);
                        }
                    }
                } catch (\Throwable $rollback) {
                    if (null !== $operationId) {
                        $this->recordFailedOperation($operationId, $archive, $manifest, 'rollback_failed');
                    }
                    throw new \RuntimeException('Update failed and automatic rollback failed; delivery remains quiesced.', previous: $rollback);
                }
            }
            if (null !== $operationId) {
                $this->recordFailedOperation($operationId, $archive, $manifest, 'update_failed');
            }
            if (null !== $target) {
                $this->removeFailedRelease($target);
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function schemaFingerprint(): string
    {
        // Application backups restore rows and assets, not MariaDB DDL or its migration ledger.
        // Never switch older code back merely because that data restore succeeded.
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);
        $definitions = [];
        foreach ($tables as $table) {
            $definition = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteSingleIdentifier($table));
            if (false === $definition) {
                throw new \RuntimeException('Database schema recovery verification is unavailable.');
            }
            foreach ($definition as $name => $value) {
                if (!\is_string($value)) {
                    throw new \RuntimeException('Database schema recovery verification is invalid.');
                }
                // Restoring rows changes AUTO_INCREMENT counters, not the table definition.
                $definition[$name] = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', $value);
            }
            $definitions[$table] = $definition;
        }
        $versions = $this->connection->fetchAllAssociative('SELECT version, executed_at, execution_time FROM doctrine_migration_versions ORDER BY version');

        return hash('sha256', serialize([$definitions, $versions]));
    }

    /** @param array<string, mixed> $manifest */
    private function recordFailedOperation(string $id, string $archive, array $manifest, string $detail): void
    {
        $target = \is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
        $hash = hash_file('sha256', $archive);
        $this->connection->executeStatement("INSERT INTO release_operation (id, operation, source_version, target_version, package_sha256, status, detail_code, started_at, finished_at) VALUES (?, 'update', ?, ?, ?, 'failed', ?, ?, ?) ON DUPLICATE KEY UPDATE status = 'failed', detail_code = VALUES(detail_code), finished_at = VALUES(finished_at)", [$id, $this->currentVersion, $target, false === $hash ? null : $hash, $detail, Identity::now(), Identity::now()]);
    }

    /** @param array<string, mixed> $manifest */
    private function assertPlatformAndSchemaCompatibility(array $manifest): void
    {
        $minimumSchema = $manifest['minimum_schema_version'] ?? null;
        $targetSchema = $manifest['schema_version'] ?? null;
        $currentSchema = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY executed_at DESC, version DESC LIMIT 1');
        $currentSchema = \is_string($currentSchema) ? substr($currentSchema, -14) : null;
        if (!\is_string($minimumSchema) || !\is_string($targetSchema) || !\is_string($currentSchema) || 1 !== preg_match('/^[0-9]{14}$/', $minimumSchema) || 1 !== preg_match('/^[0-9]{14}$/', $targetSchema) || $currentSchema < $minimumSchema || $targetSchema < $currentSchema) {
            throw new \RuntimeException('Release database schema compatibility check failed.');
        }
        $platform = $manifest['platform'] ?? null;
        $mariaDb = \is_array($platform) ? ($platform['mariadb'] ?? null) : null;
        $server = $this->connection->fetchOne('SELECT VERSION()');
        if (!\is_array($mariaDb) || ['10.11', '11.8'] !== array_values($mariaDb) || !\is_string($server)) {
            throw new \RuntimeException('Release database platform compatibility is unavailable.');
        }
        if (1 !== preg_match('/^(\d+\.\d+)/', $server, $matches) || !\in_array($matches[1], $mariaDb, true)) {
            throw new \RuntimeException('Release MariaDB compatibility check failed.');
        }
    }

    /** @param array<string, string> $files */
    private function assertExtracted(string $root, array $files): void
    {
        foreach ($files as $name => $digest) {
            $path = $root.'/'.$name;
            $stat = @lstat($path);
            if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || !hash_equals($digest, (string) hash_file('sha256', $path))) {
                throw new \RuntimeException('Extracted release verification failed.');
            }
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertInstalledReleaseMatchesManifest(string $release, array $manifest): void
    {
        $version = $manifest['version'] ?? null;
        $files = $manifest['files'] ?? null;
        if (!\is_string($version) || !hash_equals($this->currentVersion, $version) || !\is_array($files)) {
            throw new \RuntimeException('Signed predecessor package identity does not match the recorded update source.');
        }
        $expected = $this->stringMap($files);
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($release, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Installed predecessor differs from its signed package.');
            }
            if ($entry->isDir()) {
                continue;
            }
            $path = $entry->getPathname();
            $relative = substr($path, \strlen(rtrim($release, '/')) + 1);
            $metadata = @lstat($path);
            $digest = hash_file('sha256', $path);
            if (!\is_array($metadata)
                || 0o100000 !== ($metadata['mode'] & 0o170000)
                || 1 !== $metadata['nlink']
                || !\is_string($digest)
                || !\is_string($expected[$relative] ?? null)
                || !hash_equals($expected[$relative], $digest)
            ) {
                throw new \RuntimeException('Installed predecessor differs from its signed package.');
            }
            $seen[$relative] = true;
        }
        if (\count($seen) !== \count($expected) || [] !== array_diff_key($expected, $seen)) {
            throw new \RuntimeException('Installed predecessor differs from its signed package.');
        }
    }

    private function switchPointer(string $target): void
    {
        $temporary = $this->releasesDirectory.'/.current-'.Identity::uuidV7();
        if (!symlink($target, $temporary) || !rename($temporary, $this->releasesDirectory.'/current')) {
            @unlink($temporary);
            throw new \RuntimeException('Atomic release pointer switch failed.');
        }
    }

    /** @param list<string> $arguments
     * @param list<int> $acceptedStatuses
     */
    private function runConsole(string $release, array $arguments, array $acceptedStatuses): void
    {
        $command = array_merge([PHP_BINARY, $release.'/bin/console'], $arguments);
        $pipes = [];
        $environment = LegacyDatabaseEnvironment::forRelease($release, getenv());
        $process = proc_open(LegacyDatabaseEnvironment::command($command, $environment), [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $release, $environment);
        if (!\is_resource($process)) {
            throw new \RuntimeException('Release command could not start.');
        }
        $status = proc_close($process);
        if (!\in_array($status, $acceptedStatuses, true)) {
            throw new \RuntimeException('Release command failed with redacted status '.$status.'.');
        }
    }

    private function ensureReleaseRoot(): void
    {
        if ('' === $this->releasesDirectory || !str_starts_with($this->releasesDirectory, '/') || str_contains($this->releasesDirectory, '/public/')) {
            throw new \RuntimeException('Release directory configuration is unsafe.');
        }
        if (!is_dir($this->releasesDirectory) && !mkdir($this->releasesDirectory, 0o755, true)) {
            throw new \RuntimeException('Release directory cannot be created.');
        }
    }

    private function freeze(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Extracted release contains an unsafe object.');
            }
            if (!chmod($entry->getPathname(), $entry->isDir() ? 0o555 : 0o444)) {
                throw new \RuntimeException('Immutable release permissions cannot be applied.');
            }
        }
        if (!chmod($directory, 0o555)) {
            throw new \RuntimeException('Immutable release root permissions cannot be applied.');
        }
    }

    private function assertPublishedOwnership(string $directory): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        if (!\function_exists('posix_geteuid') || 0 !== posix_geteuid()) {
            throw new \RuntimeException('Production release publication requires the root-controlled updater identity.');
        }

        $paths = [$directory];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                throw new \RuntimeException('Immutable release ownership validation found an unsafe object.');
            }
            $paths[] = $entry->getPathname();
        }
        foreach ($paths as $path) {
            $metadata = @lstat($path);
            if (!\is_array($metadata) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o222)) {
                throw new \RuntimeException('Production release is not root-owned and immutable.');
            }
        }
    }

    private function transferSafetyBackupToServiceOwner(string $path): void
    {
        if ('prod' !== $this->environment) {
            return;
        }
        $before = @lstat($path);
        $parent = @lstat(\dirname($path));
        if (!\is_array($before)
            || !\is_array($parent)
            || 0o100000 !== ($before['mode'] & 0o170000)
            || 0 !== $before['uid']
            || 0o600 !== ($before['mode'] & 0o777)
            || 1 !== $before['nlink']
            || 0o040000 !== ($parent['mode'] & 0o170000)
            || 0 === $parent['uid']
            || 0o700 !== ($parent['mode'] & 0o777)
            || !\function_exists('lchown')
            || !\function_exists('lchgrp')
            || !@lchown($path, $parent['uid'])
            || !@lchgrp($path, $parent['gid'])
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership cannot be returned to the service identity.');
        }
        $after = @lstat($path);
        if ($before['ino'] !== $after['ino']
            || $before['dev'] !== $after['dev']
            || $parent['uid'] !== $after['uid']
            || $parent['gid'] !== $after['gid']
            || 0o600 !== ($after['mode'] & 0o777)
            || 1 !== $after['nlink']
        ) {
            throw new \RuntimeException('Verified update safety-backup ownership changed unsafely.');
        }
    }

    /** @param array<string, mixed> $manifest */
    private function assertDiskCapacity(string $archive, array $manifest): void
    {
        $this->ensureReleaseRoot();
        $size = filesize($archive);
        $uncompressed = $manifest['_verified_uncompressed_bytes'] ?? null;
        $free = disk_free_space($this->releasesDirectory);
        if (false === $size || !\is_int($uncompressed) || false === $free || $free < $size + (2 * $uncompressed) + $this->rollbackReserveBytes) {
            throw new \RuntimeException('Insufficient disk space for a staged update and rollback reserve.');
        }
    }

    private function removeStaging(string $directory): void
    {
        if (!str_starts_with($directory, rtrim($this->releasesDirectory, '/').'/.staging-') || !is_dir($directory)) {
            return;
        }
        @chmod($directory, 0o700);
        $permissions = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($permissions as $entry) {
            if ($entry instanceof \SplFileInfo && !$entry->isLink()) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($directory);
    }

    private function removeFailedRelease(string $directory): void
    {
        $parent = realpath($this->releasesDirectory);
        $current = realpath($this->releasesDirectory.'/current');
        if (false === $parent || $directory === $current || \dirname($directory) !== $parent || 1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', basename($directory)) || !is_dir($directory) || is_link($directory)) {
            return;
        }
        @chmod($directory, 0o700);
        $permissions = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($permissions as $entry) {
            if ($entry instanceof \SplFileInfo && !$entry->isLink()) {
                @chmod($entry->getPathname(), $entry->isDir() ? 0o700 : 0o600);
            }
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<string, string>
     */
    private function stringMap(array $values): array
    {
        $result = [];
        foreach ($values as $key => $value) {
            if (!\is_string($key) || !\is_string($value)) {
                throw new \RuntimeException('Release file manifest is invalid.');
            }
            $result[$key] = $value;
        }

        return $result;
    }
}
