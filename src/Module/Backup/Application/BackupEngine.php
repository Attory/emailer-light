<?php

declare(strict_types=1);

namespace App\Module\Backup\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Backup\Application\Contract\BackupContributor;
use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;
use Symfony\Component\Lock\LockFactory;

final readonly class BackupEngine implements BackupOperations
{
    private const FORMAT = 'emailer-light-backup-v1';
    private const CHUNK = 65536;

    /** @param iterable<BackupContributor> $contributors */
    public function __construct(private Connection $connection, private MasterKey $masterKey, private LockFactory $locks, private MaintenanceOperationLock $maintenance, private iterable $contributors, private string $backupDirectory, private string $sourceVersion, private ?RestoreDrillEvidence $drillEvidence = null, private ?AuditSink $audit = null, private ?string $expectedSchemaVersion = null)
    {
    }

    /** @return array{id: string, path: string, sha256: string, bytes: int} */
    public function create(): array
    {
        $this->maintenance->acquire();
        try {
            return $this->createWithLock();
        } finally {
            $this->maintenance->release();
        }
    }

    /** @return array{id: string, path: string, sha256: string, bytes: int} */
    private function createWithLock(): array
    {
        $lock = $this->locks->createLock('emailer-backup-create', 3600.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another backup creation is active.');
        }
        try {
            $this->ensurePrivateDirectory();
        } catch (\Throwable $exception) {
            $lock->release();
            throw $exception;
        }
        $id = Identity::uuidV7();
        $filename = 'backup-'.$id.'.enxbackup';
        $path = $this->backupDirectory.'/'.$filename;
        $plain = tempnam($this->backupDirectory, '.backup-plain-');
        if (false === $plain) {
            $lock->release();
            throw new \RuntimeException('Backup staging cannot be created.');
        }
        @chmod($plain, 0o600);
        try {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->connection->beginTransaction();
            try {
                $this->writeArchive($plain, $id);
                $this->connection->commit();
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }
            $this->encrypt($plain, $path);
            $hash = hash_file('sha256', $path);
            $bytes = filesize($path);
            if (false === $hash || false === $bytes) {
                throw new \RuntimeException('Backup evidence cannot be read.');
            }
            $this->connection->insert('backup_record', ['id' => $id, 'format_version' => 1, 'filename' => $filename, 'ciphertext_sha256' => $hash, 'byte_size' => $bytes, 'schema_version' => $this->schemaVersion(), 'source_version' => $this->sourceVersion, 'status' => 'created', 'created_at' => Identity::now(), 'verified_at' => null]);
            $this->verify($path);

            return ['id' => $id, 'path' => $path, 'sha256' => $hash, 'bytes' => $bytes];
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        } finally {
            @unlink($plain);
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function verify(string $path): array
    {
        $manifest = $this->inspect($path);
        $this->connection->executeStatement("UPDATE backup_record SET status = 'verified', verified_at = ? WHERE filename = ? AND ciphertext_sha256 = ?", [Identity::now(), basename($path), hash_file('sha256', $path)]);

        return $manifest;
    }

    /** @return array<string, mixed> */
    private function inspect(string $path): array
    {
        $this->assertSafePath($path);
        $plain = tempnam($this->backupDirectory, '.backup-verify-');
        if (false === $plain) {
            throw new \RuntimeException('Backup verification staging cannot be created.');
        }
        @chmod($plain, 0o600);
        try {
            $this->decrypt($path, $plain);
            $zip = new \ZipArchive();
            if (true !== $zip->open($plain)) {
                throw new \RuntimeException('Backup archive is invalid.');
            }
            try {
                $manifestStat = $zip->statName('manifest.json');
                if (false === $manifestStat || $manifestStat['size'] < 1 || $manifestStat['size'] > 8388608) {
                    throw new \RuntimeException('Backup manifest size is invalid.');
                }
                $manifestJson = $zip->getFromName('manifest.json');
                if (false === $manifestJson) {
                    throw new \RuntimeException('Backup manifest is missing.');
                }
                $manifest = json_decode($manifestJson, true, 16, JSON_THROW_ON_ERROR);
                if (!\is_array($manifest) || self::FORMAT !== ($manifest['format'] ?? null) || 1 !== ($manifest['format_version'] ?? null) || !\is_array($manifest['entries'] ?? null)) {
                    throw new \RuntimeException('Backup manifest is incompatible.');
                }
                $manifest = $this->associative($manifest);
                $entries = $manifest['entries'] ?? null;
                if (!\is_array($entries)) {
                    throw new \RuntimeException('Backup manifest entries are invalid.');
                }
                if (100000 < \count($entries)
                    || !\is_string($manifest['backup_id'] ?? null)
                    || 1 !== preg_match('/^[0-9a-f-]{36}$/', $manifest['backup_id'])
                    || !\is_string($manifest['source_version'] ?? null)
                    || 1 !== preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(?:-[0-9A-Za-z.-]+)?$/', $manifest['source_version'])
                    || !\is_string($manifest['schema_version'] ?? null)
                    || 1 !== preg_match('/^[0-9]{14}$/', $manifest['schema_version'])
                    || !\is_string($manifest['created_at'] ?? null)
                    || 1 !== preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}$/', $manifest['created_at'])
                ) {
                    throw new \RuntimeException('Backup manifest metadata is invalid.');
                }
                $tables = $manifest['tables'] ?? null;
                if (!\is_array($tables) || $tables !== $this->tables()) {
                    throw new \RuntimeException('Backup table inventory is incompatible.');
                }
                $seen = [];
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $name = $zip->getNameIndex($index);
                    if (false === $name || isset($seen[$name]) || ('manifest.json' !== $name && !isset($entries[$name]))) {
                        throw new \RuntimeException('Backup archive contains an unexpected entry.');
                    }
                    $seen[$name] = true;
                }
                foreach ($entries as $name => $digest) {
                    if (!\is_string($name) || !\is_string($digest) || !isset($seen[$name]) || !$this->safeArchiveEntry($name) || 1 !== preg_match('/^[a-f0-9]{64}$/', $digest)) {
                        throw new \RuntimeException('Backup manifest entry is invalid.');
                    }
                    $stream = $zip->getStream($name);
                    if (false === $stream) {
                        throw new \RuntimeException('Backup content is missing.');
                    }
                    $hash = hash_init('sha256');
                    hash_update_stream($hash, $stream);
                    fclose($stream);
                    if (!hash_equals($digest, hash_final($hash))) {
                        throw new \RuntimeException('Backup content verification failed.');
                    }
                }
            } finally {
                $zip->close();
            }

            return $manifest;
        } finally {
            @unlink($plain);
        }
    }

    public function restore(string $path): void
    {
        $this->maintenance->acquire();
        try {
            $this->restoreWithLock($path);
        } finally {
            $this->maintenance->release();
        }
    }

    public function restoreForDrill(string $path): string
    {
        $this->maintenance->acquire();
        try {
            $receipt = $this->restoreWithLock($path, true);
            if (null === $receipt) {
                throw new \RuntimeException('Restore-drill evidence was not published.');
            }

            return $receipt;
        } finally {
            $this->maintenance->release();
        }
    }

    public function recordRestoreDrill(string $path): void
    {
        $this->maintenance->acquire();
        try {
            $this->assertSafePath($path);
            $handle = @fopen($path, 'rb');
            if (false === $handle) {
                throw new \RuntimeException('Restore-drill evidence cannot be read.');
            }
            try {
                $before = fstat($handle);
                $contents = stream_get_contents($handle, 4097);
                $after = @lstat($path);
                if (false === $before || false === $after || false === $contents || $before['dev'] !== $after['dev'] || $before['ino'] !== $after['ino'] || 1 !== $before['nlink'] || 1 !== $after['nlink'] || 0 !== ($before['mode'] & 0o077) || 0 !== ($after['mode'] & 0o077) || 4096 < $before['size'] || $before['size'] !== \strlen($contents)) {
                    throw new \RuntimeException('Restore-drill evidence is unsafe.');
                }
            } finally {
                fclose($handle);
            }
            $payload = $this->evidence()->authenticate($contents);
            $record = $this->connection->fetchAssociative("SELECT * FROM backup_record WHERE id = ? AND status = 'verified' AND deleted_at IS NULL", [$payload['backup_id']]);
            if (false === $record
                || $record['ciphertext_sha256'] !== $payload['ciphertext_sha256']
                || $this->integer($record['byte_size']) !== $payload['byte_size']
                || $record['source_version'] !== $payload['source_version']
                || $record['schema_version'] !== $payload['schema_version']
                || !\is_string($record['created_at']) || $payload['restored_at'] < $record['created_at']
                || $payload['restored_at'] > Identity::now()
                || !\is_string($record['filename']) || basename($record['filename']) !== $record['filename']
            ) {
                throw new \RuntimeException('Restore-drill evidence does not match a retained verified backup.');
            }
            $backup = $this->backupDirectory.'/'.$record['filename'];
            $manifest = $this->inspect($backup);
            $entries = $manifest['entries'];
            if (!\is_array($entries)) {
                throw new \RuntimeException('Restore-drill evidence backup entries are invalid.');
            }
            $assetCount = 0;
            foreach (array_keys($entries) as $name) {
                if (\is_string($name) && str_starts_with($name, 'assets/')) {
                    ++$assetCount;
                }
            }
            if ($manifest['backup_id'] !== $payload['backup_id'] || $manifest['source_version'] !== $payload['source_version'] || $manifest['schema_version'] !== $payload['schema_version'] || !hash_equals($payload['ciphertext_sha256'], (string) hash_file('sha256', $backup)) || filesize($backup) !== $payload['byte_size'] || $payload['checked_tables'] !== \count($this->tables()) || $payload['checked_assets'] !== $assetCount) {
                throw new \RuntimeException('Restore-drill evidence backup verification failed.');
            }
            if (null === $this->audit) {
                throw new \RuntimeException('Restore-drill audit is unavailable.');
            }
            $this->connection->transactional(function () use ($payload): void {
                $changed = $this->connection->executeStatement('UPDATE backup_record SET restored_at = ? WHERE id = ? AND (restored_at IS NULL OR restored_at < ?)', [$payload['restored_at'], $payload['backup_id'], $payload['restored_at']]);
                if (0 < $changed) {
                    $this->audit->append(new AuditRecord(null, 'backup.restore_drill_recorded', $payload['backup_id'], 'success', ['checked_tables' => $payload['checked_tables'], 'checked_assets' => $payload['checked_assets']]));
                }
            });
        } finally {
            $this->maintenance->release();
        }
    }

    private function restoreWithLock(string $path, bool $drill = false): ?string
    {
        $lock = $this->locks->createLock('emailer-backup-restore', 3600.0);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another backup restore is active.');
        }
        $maintenanceConfirmed = false;
        $receipt = null;
        try {
            try {
                $this->assertQuiesced();
                $manifest = $this->inspect($path);
                $liveSchema = $this->schemaVersion();
                if ($manifest['schema_version'] !== $liveSchema || (null !== $this->expectedSchemaVersion && $this->expectedSchemaVersion !== $liveSchema)) {
                    throw new \RuntimeException('This backup belongs to a different database schema. Nothing was restored. Use the matching signed release in an isolated recovery installation, or the guided updater’s retained recovery operation. Keep this backup for recovery; it can still be verified.');
                }
                $maintenanceConfirmed = true;
                if ($drill) {
                    $this->evidence();
                }
                $safety = $this->create();
                try {
                    $this->apply($path);
                    if ($drill) {
                        $checked = $this->validateRestoredSnapshot($path);
                    }
                    $this->recordVerified($path, $manifest);
                    if ($drill) {
                        $receipt = $this->evidence()->issue([
                            'format' => 'emailer-light-restore-drill-v1',
                            'backup_id' => $manifest['backup_id'],
                            'ciphertext_sha256' => hash_file('sha256', $path),
                            'byte_size' => filesize($path),
                            'source_version' => $manifest['source_version'],
                            'schema_version' => $manifest['schema_version'],
                            'restored_at' => Identity::now(),
                            'checked_tables' => $checked['tables'],
                            'checked_assets' => $checked['assets'],
                        ]);
                    }
                } catch (\Throwable $exception) {
                    try {
                        $safetyManifest = $this->verify($safety['path']);
                        $this->apply($safety['path']);
                        $this->recordVerified($safety['path'], $safetyManifest, false);
                    } catch (\Throwable $rollback) {
                        throw new \RuntimeException('Restore failed and automatic rollback also failed; keep delivery quiesced.', previous: $rollback);
                    }
                    throw new \RuntimeException('Restore failed; the pre-restore backup was reapplied.', previous: $exception);
                }
            } finally {
                if ($maintenanceConfirmed) {
                    $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work = 0, active_claims = 0, updated_at = ? WHERE singleton_key = '1'", [Identity::now()]);
                }
            }
            if (null !== $receipt) {
                $receiptPath = $this->backupDirectory.'/restore-drill-'.Identity::uuidV7().'.json';
                $handle = @fopen($receiptPath, 'x+b');
                if (false === $handle) {
                    throw new \RuntimeException('Restore-drill evidence cannot be published.');
                }
                try {
                    if (!chmod($receiptPath, 0o600) || fwrite($handle, $receipt) !== \strlen($receipt) || !fflush($handle) || !fsync($handle)) {
                        @unlink($receiptPath);
                        throw new \RuntimeException('Restore-drill evidence cannot be published safely.');
                    }
                } finally {
                    fclose($handle);
                }

                return $receiptPath;
            }

            return null;
        } finally {
            $lock->release();
        }
    }

    /** @return array{tables: int, assets: int} */
    private function validateRestoredSnapshot(string $path): array
    {
        $plain = tempnam($this->backupDirectory, '.drill-verify-');
        if (false === $plain) {
            throw new \RuntimeException('Restore-drill validation staging cannot be created.');
        }
        @chmod($plain, 0o600);
        try {
            $this->decrypt($path, $plain);
            $zip = new \ZipArchive();
            if (true !== $zip->open($plain)) {
                throw new \RuntimeException('Restore-drill archive is invalid.');
            }
            try {
                foreach ($this->tables() as $table) {
                    $primary = $this->connection->createSchemaManager()->introspectTable($table)->getPrimaryKey();
                    if (null === $primary) {
                        throw new \RuntimeException('Restore-drill table has no primary key.');
                    }
                    $columns = $primary->getColumns();
                    $rows = 0;
                    $batch = [];
                    $batchBytes = 0;
                    foreach ($this->rows($zip, $table) as $expected) {
                        $batch[] = $expected;
                        $batchBytes += \strlen(json_encode($expected, JSON_THROW_ON_ERROR));
                        if (200 <= \count($batch) || 8388608 <= $batchBytes) {
                            $this->validateRowBatch($table, $columns, $batch);
                            $batch = [];
                            $batchBytes = 0;
                        }
                        ++$rows;
                    }
                    if ([] !== $batch) {
                        $this->validateRowBatch($table, $columns, $batch);
                    }
                    if ($rows !== $this->integer($this->connection->fetchOne('SELECT COUNT(*) FROM '.$this->connection->quoteSingleIdentifier($table)))) {
                        throw new \RuntimeException('Restore-drill row count does not match the backup.');
                    }
                }
                $assetCount = 0;
                foreach ($this->assets() as $logical => $root) {
                    $expectedFiles = [];
                    $prefix = 'assets/'.$logical.'/';
                    for ($index = 0; $index < $zip->numFiles; ++$index) {
                        $name = $zip->getNameIndex($index);
                        if (false === $name || !str_starts_with($name, $prefix)) {
                            continue;
                        }
                        $relative = substr($name, \strlen($prefix));
                        $stream = $zip->getStream($name);
                        if (false === $stream) {
                            throw new \RuntimeException('Restore-drill asset cannot be read.');
                        }
                        try {
                            $hash = hash_init('sha256');
                            hash_update_stream($hash, $stream);
                            $file = $root.'/'.$relative;
                            if (is_link($file) || !is_file($file) || hash_file('sha256', $file) !== hash_final($hash)) {
                                throw new \RuntimeException('Restore-drill assets do not match the backup.');
                            }
                        } finally {
                            fclose($stream);
                        }
                        $expectedFiles[] = $relative;
                        ++$assetCount;
                    }
                    $actualFiles = [];
                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                        if (!$file instanceof \SplFileInfo || $file->isLink() || !$file->isFile()) {
                            throw new \RuntimeException('Restore-drill asset storage is unsafe.');
                        }
                        $actualFiles[] = substr($file->getPathname(), \strlen($root) + 1);
                    }
                    sort($expectedFiles);
                    sort($actualFiles);
                    if ($expectedFiles !== $actualFiles) {
                        throw new \RuntimeException('Restore-drill asset inventory does not match the backup.');
                    }
                }

                return ['tables' => \count($this->tables()), 'assets' => $assetCount];
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($plain);
        }
    }

    /**
     * @param list<string>               $columns
     * @param list<array<string, mixed>> $batch
     */
    private function validateRowBatch(string $table, array $columns, array $batch): void
    {
        $expectedRows = [];
        $conditions = [];
        $values = [];
        foreach ($batch as $expected) {
            $identity = [];
            $rowConditions = [];
            foreach ($columns as $column) {
                $value = $expected[$column] ?? null;
                if (!\is_scalar($value)) {
                    throw new \RuntimeException('Restore-drill row identity is invalid.');
                }
                $identity[] = (string) $value;
                $rowConditions[] = $this->connection->quoteSingleIdentifier($column).' = ?';
                $values[] = $value;
            }
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            if (isset($expectedRows[$key])) {
                throw new \RuntimeException('Restore-drill row identity is duplicated.');
            }
            $expectedRows[$key] = $this->normalizedRow($expected);
            $conditions[] = '('.implode(' AND ', $rowConditions).')';
        }
        foreach ($this->connection->executeQuery('SELECT * FROM '.$this->connection->quoteSingleIdentifier($table).' WHERE '.implode(' OR ', $conditions), $values)->iterateAssociative() as $actual) {
            $identity = [];
            foreach ($columns as $column) {
                $value = $actual[$column] ?? null;
                if (!\is_scalar($value)) {
                    throw new \RuntimeException('Restore-drill row identity is invalid.');
                }
                $identity[] = (string) $value;
            }
            $key = json_encode($identity, JSON_THROW_ON_ERROR);
            if (!isset($expectedRows[$key]) || $expectedRows[$key] !== $this->normalizedRow($actual)) {
                throw new \RuntimeException('Restore-drill rows do not match the backup.');
            }
            unset($expectedRows[$key]);
        }
        if ([] !== $expectedRows) {
            throw new \RuntimeException('Restore-drill rows are missing.');
        }
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, string|null>
     */
    private function normalizedRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $column => $value) {
            if (null !== $value && !\is_scalar($value)) {
                throw new \RuntimeException('Restore-drill row value is invalid.');
            }
            $normalized[$column] = null === $value ? null : (string) $value;
        }
        ksort($normalized);

        return $normalized;
    }

    private function evidence(): RestoreDrillEvidence
    {
        if (null === $this->drillEvidence) {
            throw new \RuntimeException('Restore-drill evidence service is unavailable.');
        }

        return $this->drillEvidence;
    }

    public function isRecentVerified(string $path, int $now): bool
    {
        try {
            $this->verify($path);
            $created = $this->connection->fetchOne("SELECT UNIX_TIMESTAMP(created_at) FROM backup_record WHERE filename = ? AND status = 'verified'", [basename($path)]);

            return is_numeric($created) && (int) $created <= $now && (int) $created >= $now - 86400;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<array<string, mixed>> */
    public function recentRecords(): array
    {
        return $this->connection->fetchAllAssociative('SELECT id, format_version, byte_size, schema_version, source_version, status, created_at, verified_at, restored_at, deleted_at FROM backup_record ORDER BY created_at DESC LIMIT 20');
    }

    /** @return list<array<string, mixed>> */
    public function recentTransfers(): array
    {
        return $this->connection->fetchAllAssociative('SELECT backup_id, destination_type, byte_size, status, detail_code, attempted_at, completed_at FROM backup_transfer ORDER BY attempted_at DESC LIMIT 20');
    }

    private function writeArchive(string $path, string $id): void
    {
        $zip = new \ZipArchive();
        if (true !== $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
            throw new \RuntimeException('Backup archive cannot be created.');
        }
        $entries = [];
        $temporaryEntries = [];
        try {
            foreach ($this->tables() as $table) {
                $name = 'database/'.$table.'.jsonl';
                $tableFile = tempnam($this->backupDirectory, '.backup-table-');
                if (false === $tableFile) {
                    throw new \RuntimeException('Backup stream cannot be created.');
                }
                $temporaryEntries[] = $tableFile;
                @chmod($tableFile, 0o600);
                $stream = fopen($tableFile, 'w+b');
                if (false === $stream) {
                    throw new \RuntimeException('Backup stream cannot be opened.');
                }
                $hash = hash_init('sha256');
                foreach ($this->connection->executeQuery('SELECT * FROM `'.$table.'`')->iterateAssociative() as $row) {
                    $line = json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
                    hash_update($hash, $line);
                    if (\strlen($line) !== fwrite($stream, $line)) {
                        throw new \RuntimeException('Backup table stream cannot be written.');
                    }
                }
                fclose($stream);
                if (!$zip->addFile($tableFile, $name)) {
                    throw new \RuntimeException('Backup table cannot be added to the archive.');
                }
                $entries[$name] = hash_final($hash);
            }
            foreach ($this->assets() as $logical => $root) {
                if (!is_dir($root)) {
                    continue;
                }
                $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
                foreach ($iterator as $file) {
                    if (!$file instanceof \SplFileInfo) {
                        throw new \RuntimeException('Backup asset iterator returned an invalid entry.');
                    }
                    if (!$file->isFile() || $file->isLink()) {
                        throw new \RuntimeException('Backup asset storage contains an unsafe object.');
                    }
                    $relative = substr($file->getPathname(), \strlen(rtrim($root, '/')) + 1);
                    $name = 'assets/'.$logical.'/'.$relative;
                    $digest = hash_file('sha256', $file->getPathname());
                    if (false === $digest || !$zip->addFile($file->getPathname(), $name)) {
                        throw new \RuntimeException('Backup asset cannot be read.');
                    }
                    $entries[$name] = $digest;
                }
            }
            ksort($entries);
            $manifest = ['format' => self::FORMAT, 'format_version' => 1, 'backup_id' => $id, 'source_version' => $this->sourceVersion, 'schema_version' => $this->schemaVersion(), 'created_at' => Identity::now(), 'tables' => $this->tables(), 'entries' => $entries];
            if (!$zip->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n")) {
                throw new \RuntimeException('Backup manifest cannot be written.');
            }
        } finally {
            $zip->close();
            foreach ($temporaryEntries as $temporaryEntry) {
                @unlink($temporaryEntry);
            }
        }
    }

    private function encrypt(string $input, string $output): void
    {
        $key = $this->derivedKey();
        /** @var array{0: string, 1: string} $state */
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        [$streamState, $header] = $state;
        $in = fopen($input, 'rb');
        if (false === $in) {
            sodium_memzero($key);
            throw new \RuntimeException('Backup encryption stream cannot be opened.');
        }
        $out = fopen($output, 'x+b');
        if (false === $out) {
            fclose($in);
            sodium_memzero($key);
            throw new \RuntimeException('Backup encryption stream cannot be opened.');
        }
        @chmod($output, 0o600);
        try {
            $this->writeFully($out, self::FORMAT."\0".$header);
            while (!feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if (false === $chunk) {
                    throw new \RuntimeException('Backup input cannot be read.');
                }
                $final = feof($in);
                $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($streamState, $chunk, '', $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : 0);
                $this->writeFully($out, pack('N', \strlen($cipher)).$cipher);
            }
            if (!fflush($out)) {
                throw new \RuntimeException('Backup ciphertext cannot be flushed.');
            }
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    private function decrypt(string $input, string $output): void
    {
        $in = fopen($input, 'rb');
        if (false === $in) {
            throw new \RuntimeException('Backup decryption stream cannot be opened.');
        }
        $out = fopen($output, 'wb');
        if (false === $out) {
            fclose($in);
            throw new \RuntimeException('Backup decryption stream cannot be opened.');
        }
        $key = $this->derivedKey();
        try {
            $prefix = fread($in, \strlen(self::FORMAT) + 1);
            $header = fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            if (self::FORMAT."\0" !== $prefix || false === $header || SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES !== \strlen($header)) {
                throw new \RuntimeException('Backup envelope is invalid.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (!feof($in)) {
                $lengthBytes = fread($in, 4);
                if ('' === $lengthBytes) {
                    break;
                }
                if (false === $lengthBytes || 4 !== \strlen($lengthBytes)) {
                    throw new \RuntimeException('Backup envelope is truncated.');
                }
                $unpacked = unpack('Nlength', $lengthBytes);
                if (false === $unpacked || !isset($unpacked['length']) || !\is_int($unpacked['length'])) {
                    throw new \RuntimeException('Backup chunk length is invalid.');
                }
                $length = $unpacked['length'];
                if ($length < 17 || $length > self::CHUNK + 64) {
                    throw new \RuntimeException('Backup chunk is invalid.');
                }
                $cipher = '';
                while (\strlen($cipher) < $length && !feof($in)) {
                    $remaining = $length - \strlen($cipher);
                    if (1 > $remaining) {
                        break;
                    }
                    $part = fread($in, $remaining);
                    if (false === $part) {
                        throw new \RuntimeException('Backup chunk cannot be read.');
                    }
                    $cipher .= $part;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if (false === $result || $final) {
                    throw new \RuntimeException('Backup authentication failed.');
                }
                [$plain, $tag] = $result;
                if (!\is_string($plain)) {
                    throw new \RuntimeException('Backup plaintext chunk is invalid.');
                }
                $this->writeFully($out, $plain);
                $final = SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL === $tag;
            }
            if (!$final) {
                throw new \RuntimeException('Backup final authentication tag is missing.');
            }
        } finally {
            fclose($in);
            fclose($out);
            sodium_memzero($key);
        }
    }

    private function apply(string $path): void
    {
        $plain = tempnam($this->backupDirectory, '.backup-restore-');
        if (false === $plain) {
            throw new \RuntimeException('Restore staging cannot be created.');
        }
        try {
            $this->decrypt($path, $plain);
            $zip = new \ZipArchive();
            if (true !== $zip->open($plain)) {
                throw new \RuntimeException('Restore archive is invalid.');
            }
            $tables = $this->tables();
            $generatedColumns = [];
            foreach ($this->connection->fetchAllAssociative("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%'") as $column) {
                if (\is_string($column['TABLE_NAME'] ?? null) && \is_string($column['COLUMN_NAME'] ?? null)) {
                    $generatedColumns[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
                }
            }
            foreach ($tables as $table) {
                foreach ($this->rows($zip, $table) as $row) {
                    unset($row);
                }
            }
            $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach (array_reverse($tables) as $table) {
                    $this->connection->executeStatement('TRUNCATE TABLE `'.$table.'`');
                }
                foreach ($tables as $table) {
                    foreach ($this->rows($zip, $table) as $row) {
                        foreach ($generatedColumns[$table] ?? [] as $column) {
                            unset($row[$column]);
                        }
                        $this->connection->insert($table, $row);
                    }
                }
                $this->restoreAssets($zip);
            } finally {
                $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                $zip->close();
            }
        } finally {
            @unlink($plain);
        }
    }

    private function assertQuiesced(): void
    {
        $row = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $row || 0 !== $this->integer($row['accepting_work']) || 0 !== $this->integer($row['active_claims'])) {
            throw new \RuntimeException('Restore requires maintenance mode with no active delivery claims.');
        }
    }

    /** @return \Generator<int, array<string, mixed>> */
    private function rows(\ZipArchive $zip, string $table): \Generator
    {
        $stream = $zip->getStream('database/'.$table.'.jsonl');
        if (false === $stream) {
            throw new \RuntimeException('Restore table is missing.');
        }
        try {
            while (false !== ($line = fgets($stream, 5242881))) {
                if (5242880 < \strlen($line) || !str_ends_with($line, "\n")) {
                    throw new \RuntimeException('Restore row exceeds its safe bound or is truncated.');
                }
                $row = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (!\is_array($row)) {
                    throw new \RuntimeException('Restore row is invalid.');
                }
                yield $this->associative($row);
            }
        } finally {
            fclose($stream);
        }
    }

    private function restoreAssets(\ZipArchive $zip): void
    {
        foreach ($this->assets() as $logical => $root) {
            $parent = \dirname($root);
            if (!is_dir($parent) || is_link($parent) || (file_exists($root) && (is_link($root) || !is_dir($root)))) {
                throw new \RuntimeException('Restore asset destination is unsafe.');
            }
            $nonce = Identity::uuidV7();
            $staging = $parent.'/.restore-'.basename($root).'-'.$nonce;
            $rollback = $parent.'/.rollback-'.basename($root).'-'.$nonce;
            if (!mkdir($staging, 0o755)) {
                throw new \RuntimeException('Restore asset staging cannot be created.');
            }
            try {
                $prefix = 'assets/'.$logical.'/';
                for ($index = 0; $index < $zip->numFiles; ++$index) {
                    $name = $zip->getNameIndex($index);
                    if (false === $name || !str_starts_with($name, $prefix) || str_ends_with($name, '/')) {
                        continue;
                    }
                    $relative = substr($name, \strlen($prefix));
                    if ('' === $relative || 1 === preg_match('~(^|/)\.\.(/|$)~', $relative) || str_starts_with($relative, '/')) {
                        throw new \RuntimeException('Restore asset path is unsafe.');
                    }
                    $destination = $staging.'/'.$relative;
                    $directory = \dirname($destination);
                    if (!is_dir($directory) && !mkdir($directory, 0o755, true)) {
                        throw new \RuntimeException('Restore asset directory cannot be created.');
                    }
                    $input = $zip->getStream($name);
                    $output = fopen($destination, 'x+b');
                    if (false === $input || false === $output) {
                        throw new \RuntimeException('Restore asset stream cannot be opened.');
                    }
                    try {
                        $entry = $zip->statName($name);
                        $copied = stream_copy_to_stream($input, $output);
                        if (false === $entry || false === $copied || $entry['size'] !== $copied || !fflush($output) || !chmod($destination, 0o644)) {
                            throw new \RuntimeException('Restore asset cannot be written safely.');
                        }
                    } finally {
                        fclose($input);
                        fclose($output);
                    }
                }
                $hadRoot = is_dir($root);
                if ($hadRoot && !rename($root, $rollback)) {
                    throw new \RuntimeException('Existing asset root cannot be staged for rollback.');
                }
                if (!rename($staging, $root)) {
                    if ($hadRoot) {
                        @rename($rollback, $root);
                    }
                    throw new \RuntimeException('Restored asset root cannot be published.');
                }
                if ($hadRoot) {
                    $this->removeTree($rollback);
                }
            } finally {
                $this->removeTree($staging);
            }
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            return;
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

    private function derivedKey(): string
    {
        $master = $this->masterKey->load();
        try {
            return hash_hkdf('sha256', $master, 32, 'emailer-light/backup/v1');
        } finally {
            sodium_memzero($master);
        }
    }

    private function integer(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw new \UnexpectedValueException('Backup numeric state is invalid.');
        }

        return (int) $value;
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<string, mixed>
     */
    private function associative(array $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Backup associative data is invalid.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return list<string> */
    private function tables(): array
    {
        $tables = [];
        foreach ($this->contributors as $contributor) {
            array_push($tables, ...$contributor->tables());
        }

        return array_values(array_unique($tables));
    }

    /** @return array<string, string> */
    private function assets(): array
    {
        $assets = [];
        foreach ($this->contributors as $contributor) {
            $assets += $contributor->assetRoots();
        }

        return $assets;
    }

    private function schemaVersion(): string
    {
        $version = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');

        return \is_string($version) ? substr($version, -14) : 'unknown';
    }

    /** @param array<string, mixed> $manifest */
    private function recordVerified(string $path, array $manifest, bool $restored = true): void
    {
        $id = $manifest['backup_id'] ?? null;
        $schema = $manifest['schema_version'] ?? null;
        $source = $manifest['source_version'] ?? null;
        $created = $manifest['created_at'] ?? null;
        $hash = hash_file('sha256', $path);
        $bytes = filesize($path);
        if (!\is_string($id) || !\is_string($schema) || !\is_string($source) || !\is_string($created) || false === $hash || false === $bytes) {
            throw new \RuntimeException('Restored backup evidence is invalid.');
        }
        $this->connection->executeStatement("INSERT INTO backup_record (id, format_version, filename, ciphertext_sha256, byte_size, schema_version, source_version, status, created_at, verified_at) VALUES (?, 1, ?, ?, ?, ?, ?, 'verified', ?, ?) ON DUPLICATE KEY UPDATE ciphertext_sha256 = VALUES(ciphertext_sha256), byte_size = VALUES(byte_size), status = 'verified', verified_at = VALUES(verified_at)", [$id, basename($path), $hash, $bytes, $schema, $source, $created, Identity::now()]);
        if ($restored) {
            $this->connection->update('backup_record', ['restored_at' => Identity::now()], ['id' => $id]);
        }
    }

    private function safeArchiveEntry(string $name): bool
    {
        return '' !== $name && !str_starts_with($name, '/') && !str_contains($name, "\0") && 1 !== preg_match('~(^|/)\.\.(/|$)~', $name) && (str_starts_with($name, 'database/') || str_starts_with($name, 'assets/'));
    }

    /** @param resource $stream */
    private function writeFully($stream, string $bytes): void
    {
        $offset = 0;
        while ($offset < \strlen($bytes)) {
            $written = fwrite($stream, substr($bytes, $offset));
            if (false === $written || 0 === $written) {
                throw new \RuntimeException('Backup stream cannot be written completely.');
            }
            $offset += $written;
        }
    }

    private function ensurePrivateDirectory(): void
    {
        if (!is_dir($this->backupDirectory) && (!@mkdir($this->backupDirectory, 0o700, true) || !@chmod($this->backupDirectory, 0o700))) {
            throw new \RuntimeException('Private backup directory cannot be created.');
        }
        if (!is_dir($this->backupDirectory) || !is_writable($this->backupDirectory) || str_contains((string) realpath($this->backupDirectory), '/public/')) {
            throw new \RuntimeException('Backup directory is unsafe.');
        }
    }

    private function assertSafePath(string $path): void
    {
        $this->ensurePrivateDirectory();
        $real = realpath($path);
        $root = realpath($this->backupDirectory);
        $stat = @lstat($path);
        if (false === $real || false === $root || !str_starts_with($real.'/', rtrim($root, '/').'/') || !\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077)) {
            throw new \RuntimeException('Backup path or permissions are unsafe.');
        }
    }
}
