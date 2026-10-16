<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use App\Module\Backup\Application\Contract\BackupOperations;
use App\Module\Health\Application\Contract\RuntimeVerification;
use App\Shared\Identity;
use App\Shared\MaintenanceOperationLock;
use Doctrine\DBAL\Connection;

/** The sole native Light pair changes no migrations, DDL, user data or cryptographic context. */
final readonly class NativeSchemaUpdate
{
    /** @param iterable<\App\Module\Backup\Application\Contract\BackupContributor> $contributors */
    public function __construct(private Connection $connection, private PackageInspector $packages, private ReleaseManager $releases, private BackupOperations $backups, private RuntimeVerification $runtime, private MaintenanceOperationLock $maintenance, private NativeWriterFence $fence, private string $runtimeConfigurationFile, private string $privateDirectory, private string $masterKeyFile, private string $releasesDirectory, private iterable $contributors, private string $projectDirectory)
    {
    }

    /** @return array{status: string, delivery_held: bool} */
    public function run(string $targetArchive, string $sourceArchive, bool $recover): array
    {
        if (0 !== posix_geteuid()) {
            throw new \RuntimeException('Native publication requires the root-controlled installer.');
        }
        NativeUpdateJournal::regular($this->runtimeConfigurationFile);
        $runtimeStat = stat($this->runtimeConfigurationFile);
        $values = (new \Symfony\Component\Dotenv\Dotenv())->parse(NativeUpdateJournal::text(file_get_contents($this->runtimeConfigurationFile)));
        if (!\is_array($runtimeStat) || 0 !== $runtimeStat['uid'] || ($values['EMAILER_PRIVATE_DIR'] ?? null) !== $this->privateDirectory || ($values['EMAILER_SECRET_KEY_FILE'] ?? null) !== $this->masterKeyFile || ($values['EMAILER_RELEASES_DIR'] ?? null) !== $this->releasesDirectory) {
            throw new \RuntimeException('The root-controlled runtime and selected installation paths disagree.');
        }
        $source = $this->packages->inspectInstalledSource($sourceArchive);
        $target = $this->packages->inspect($targetArchive);
        if ('1.0.0' !== $source['version'] || '1.1.0' !== $target['version'] || '20261014000000' !== $source['schema_version'] || $source['schema_version'] !== $target['schema_version'] || $this->migrations($source) !== $this->migrations($target)) {
            throw new \RuntimeException('This native update supports only the verified schema-preserving Light 1.0.0 to 1.1.0 pair.');
        }
        $this->maintenance->acquire();
        $lock = 'emailer-light-native-'.substr(hash('sha256', NativeUpdateJournal::text($this->connection->getDatabase())), 0, 40);
        $locked = false;
        try {
            if (1 !== NativeUpdateJournal::integer($this->connection->fetchOne('SELECT GET_LOCK(?,0)', [$lock]))) {
                throw new \RuntimeException('Another database-scoped maintenance operation is active.');
            }
            $locked = true;
            $status = $this->execute($targetArchive, $sourceArchive, $source, $target, $recover);

            return ['status' => $status, 'delivery_held' => 0 === NativeUpdateJournal::integer($this->connection->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'"))];
        } finally {
            if ($locked) {
                $this->connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
            }
            $this->maintenance->release();
        }
    }

    /** @param array<string, mixed> $manifest
     * @return array<string, string>
     */
    private function migrations(array $manifest): array
    {
        if (!\is_array($manifest['files'] ?? null)) {
            throw new \RuntimeException('The migration inventory is unavailable.');
        }
        $files = [];
        foreach ($manifest['files'] as $name => $hash) {
            if (\is_string($name) && str_starts_with($name, 'migrations/')) {
                $files[$name] = NativeUpdateJournal::text($hash);
            }
        }
        ksort($files);
        if ([] === $files) {
            throw new \RuntimeException('The authenticated migration inventory is empty.');
        }

        return $files;
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    private function execute(string $targetArchive, string $sourceArchive, array $source, array $target, bool $recover): string
    {
        $base = \dirname($this->runtimeConfigurationFile).'/native-updates';
        NativeUpdateJournal::directory($base);
        $journalPath = $base.'/light-1.0.0-to-1.1.0.json';
        $sourceRoot = $this->releasesDirectory.'/1.0.0';
        $targetRoot = $this->releasesDirectory.'/1.1.0';
        $this->installed($sourceRoot, $source);
        $binding = hash('sha256', serialize([$this->connection->getDatabase(), $this->connection->fetchAssociative('SELECT @@hostname h,@@port p,@@server_id i'), $this->privateDirectory, $this->releasesDirectory]));
        $record = file_exists($journalPath) ? NativeUpdateJournal::read($journalPath) : [];
        if ([] !== $record) {
            if (($record['binding'] ?? null) !== $binding || ($record['target_sha256'] ?? null) !== hash_file('sha256', $targetArchive) || ($record['source_sha256'] ?? null) !== hash_file('sha256', $sourceArchive)) {
                throw new \RuntimeException('Retained operation and selected installation or packages differ.');
            }
            $operation = NativeUpdateJournal::text($record['id'] ?? null);
            $latest = $this->connection->fetchAssociative("SELECT id,status FROM release_operation WHERE operation='update' ORDER BY started_at DESC,id DESC LIMIT 1");
            if (false !== $latest && $latest['id'] === $operation && 'succeeded' === $latest['status']) {
                if ($recover) {
                    throw new \RuntimeException('This update already succeeded. Recovery cannot downgrade a completed installation.');
                }
                if (realpath($this->releasesDirectory.'/current') !== $targetRoot) {
                    throw new \RuntimeException('A completed update has drifted; its old snapshot cannot overwrite it.');
                }
                $this->installed($targetRoot, $target);
                if (true !== ($record['fence_restored'] ?? false)) {
                    $this->restoreFence($record);
                    $this->save($journalPath, array_replace($record, ['phase' => 'completed', 'fence_restored' => true]));
                }

                return 'already_completed';
            }
            if (false !== $latest && $latest['id'] !== $operation) {
                throw new \RuntimeException('A later operation supersedes this retained update.');
            }
            if ('completed' === ($record['phase'] ?? null)) {
                throw new \RuntimeException('Completed update evidence cannot be used as a downgrade.');
            }
            if ('recovered' === ($record['phase'] ?? null)) {
                if ($recover) {
                    if (realpath($this->releasesDirectory.'/current') !== $sourceRoot) {
                        throw new \RuntimeException('The recovered source pointer has changed.');
                    }
                    if (true !== ($record['fence_restored'] ?? false)) {
                        $this->restoreFence($record);
                        $record['fence_restored'] = true;
                        $this->save($journalPath, $record);
                    }

                    return 'already_recovered';
                }
                // Retain the previous recovery record before a deliberate retry.
                NativeUpdateJournal::write($base.'/recovered-'.$operation.'.json', json_encode($record, JSON_THROW_ON_ERROR));
                $record = [];
            } elseif (!$recover) {
                throw new \RuntimeException('An interrupted update was found. Choose Recover in setup before retrying; delivery remains held.');
            }
        }
        if ($recover) {
            if ([] === $record) {
                throw new \RuntimeException('There is no retained operation to recover.');
            }
            $this->recover($journalPath, $record, $sourceRoot, $targetRoot, $target);

            return 'recovered';
        }
        if (realpath($this->releasesDirectory.'/current') !== $sourceRoot || false !== $this->connection->fetchOne("SELECT 1 FROM release_operation WHERE status='started' OR (status='failed' AND detail_code='rollback_failed') LIMIT 1")) {
            throw new \RuntimeException('The active source or unfinished operation does not match this update.');
        }
        $proof = NativeUpdateJournal::read($this->projectDirectory.'/config/upgrade/native-schema-1.0.0.json', true);
        $migrations = $this->migrations($source);
        if ('emailer-light-native-schema-v1' !== ($proof['format'] ?? null) || ($proof['migration_sha256'] ?? null) !== ($migrations['migrations/Version20261014000000.php'] ?? null) || ($proof['definition'] ?? null) !== NativeSchemaProof::capture($this->connection)) {
            throw new \RuntimeException('The live database differs from the authenticated Light baseline. No update or schema repair was attempted.');
        }
        $ledger = $this->connection->fetchFirstColumn('SELECT version FROM doctrine_migration_versions ORDER BY version');
        if (['DoctrineMigrations\\Version20261014000000'] !== $ledger) {
            throw new \RuntimeException('The live migration ledger does not match the unchanged signed migration plan.');
        }
        $identity = $this->connection->fetchAssociative("SELECT * FROM product_identity WHERE singleton_key='1'");
        $installation = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (false === $identity || 'emailer-light' !== ($identity['product_id'] ?? null) || '1.0.0' !== ($identity['product_version'] ?? null) || $installation !== ($identity['installation_uuid'] ?? null)) {
            throw new \RuntimeException('The source installation identity is inconsistent.');
        }
        $id = Identity::uuidV7();
        $record = ['format' => 'emailer-light-native-update-v1', 'id' => $id, 'phase' => 'preparing', 'binding' => $binding, 'source_sha256' => hash_file('sha256', $sourceArchive), 'target_sha256' => hash_file('sha256', $targetArchive), 'schema' => $this->schema(), 'account' => $this->fence->identify(), 'files' => $this->captureFiles($base.'/'.$id), 'identity' => $identity];
        $this->save($journalPath, $record);
        $this->connection->insert('release_operation', ['id' => $id, 'operation' => 'update', 'source_version' => '1.0.0', 'target_version' => '1.1.0', 'package_sha256' => $record['target_sha256'], 'status' => 'started', 'detail_code' => 'verified', 'started_at' => Identity::now(), 'finished_at' => null]);
        $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
        if (0 !== NativeUpdateJournal::integer($this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'"))) {
            throw new \RuntimeException('Delivery is still finishing active work. Wait for it to drain, then choose Recover. No claim was reset.');
        }
        $this->closeFence($record);
        $backup = $this->backups->create();
        $owner = stat(\dirname($backup['path']));
        if (!\is_array($owner) || 0 === $owner['uid'] || !chown($backup['path'], $owner['uid']) || !chgrp($backup['path'], $owner['gid'])) {
            throw new \RuntimeException('The verified safety backup cannot be assigned to its dedicated application account.');
        }
        $this->backups->verify($backup['path']);
        $record['backup'] = $backup;
        $record['data'] = $this->data();
        $record['assets'] = $this->assets();
        $record['phase'] = 'checkpointed';
        $this->save($journalPath, $record);
        $this->assertStable($record, $sourceRoot);
        if (is_dir($targetRoot)) {
            $this->installed($targetRoot, $target);
        } else {
            $this->releases->install($targetArchive);
        }
        $record['phase'] = 'published';
        $this->save($journalPath, $record);
        $this->switch($targetRoot);
        $record['phase'] = 'activated';
        $this->save($journalPath, $record);
        $this->assertStable($record, $targetRoot);
        if (true !== $this->runtime->verifyRuntime(true)['runtime_verified']) {
            throw new \RuntimeException('The application failed structural verification. Choose Recover; delivery remains held.');
        }
        $this->connection->update('product_identity', ['product_version' => '1.1.0'], ['singleton_key' => '1']);
        $this->versionLock('1.1.0');
        $this->connection->update('release_operation', ['status' => 'succeeded', 'detail_code' => 'health_passed', 'finished_at' => Identity::now()], ['id' => $id]);
        $record['phase'] = 'completed';
        $this->save($journalPath, $record);
        $this->restoreFence($record);
        $record['fence_restored'] = true;
        $this->save($journalPath, $record);

        return 'updated';
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed> $target
     */
    private function recover(string $path, array $record, string $sourceRoot, string $targetRoot, array $target): void
    {
        $this->connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
        if (0 !== NativeUpdateJournal::integer($this->connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'"))) {
            throw new \RuntimeException('Active delivery work prevents recovery. No claim was reset.');
        }
        $this->closeFence($record);
        $current = realpath($this->releasesDirectory.'/current');
        if (!\in_array($current, [$sourceRoot, $targetRoot], true)) {
            throw new \RuntimeException('The active release is outside the recorded operation.');
        }
        $this->assertStable($record, NativeUpdateJournal::text($current));
        // This pair has no DDL or user-data mutation. Recover only the exact
        // installation metadata and pointer; a changed dataset requires investigation.
        $identity = $record['identity'] ?? null;
        if (!\is_array($identity) || '1.0.0' !== ($identity['product_version'] ?? null)) {
            throw new \RuntimeException('The source installation identity is unavailable.');
        }
        $this->connection->update('product_identity', ['product_version' => '1.0.0'], ['singleton_key' => '1']);
        $this->versionLock('1.0.0');
        $this->switch($sourceRoot);
        $this->connection->executeStatement("INSERT INTO release_operation (id,operation,source_version,target_version,package_sha256,status,detail_code,started_at,finished_at) VALUES (?,'update','1.0.0','1.1.0',?,'failed','verified_source_recovered',?,?) ON DUPLICATE KEY UPDATE status='failed', detail_code='verified_source_recovered', finished_at=VALUES(finished_at)", [NativeUpdateJournal::text($record['id']), NativeUpdateJournal::text($record['target_sha256']), Identity::now(), Identity::now()]);
        if (is_dir($targetRoot)) {
            $this->installed($targetRoot, $target);
            // Retain authenticated immutable target files for inspection. A retry
            // reuses them only after verifying every file below.
        }
        $record['phase'] = 'recovered';
        $this->save($path, $record);
        $this->restoreFence($record);
        $record['fence_restored'] = true;
        $this->save($path, $record);
    }

    private function versionLock(string $version): void
    {
        $path = $this->privateDirectory.'/install.lock';
        NativeUpdateJournal::regular($path);
        $s = stat($path);
        $value = json_decode(NativeUpdateJournal::text(file_get_contents($path)), true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || !\in_array($value['product_version'] ?? null, ['1.0.0', '1.1.0'], true)) {
            throw new \RuntimeException('The installation lock version has drifted.');
        }
        $value['product_version'] = $version;
        $temporary = \dirname($path).'/.native-lock-'.bin2hex(random_bytes(12));
        if (!\is_array($s) || false === file_put_contents($temporary, json_encode($value, JSON_THROW_ON_ERROR)."\n") || !chmod($temporary, $s['mode'] & 0o777) || !chown($temporary, $s['uid']) || !chgrp($temporary, $s['gid'])) {
            throw new \RuntimeException('The installation lock cannot be updated safely.');
        }
        $h = fopen($temporary, 'rb');
        if (false === $h) {
            throw new \RuntimeException('The installation lock cannot be synchronized.');
        }
        try {
            if (!fsync($h) || !rename($temporary, $path)) {
                throw new \RuntimeException('The installation lock cannot be published.');
            }
        } finally {
            fclose($h);
        }
        $directory = fopen(\dirname($path), 'rb');
        if (false === $directory) {
            throw new \RuntimeException('Installation lock directory cannot be synchronized.');
        }
        try {
            if (!fsync($directory)) {
                throw new \RuntimeException('Installation lock directory durability failed.');
            }
        } finally {
            fclose($directory);
        }
    }

    /** @param array<string, mixed> $record */
    private function assertStable(array $record, string $current): void
    {
        if ($current !== realpath($this->releasesDirectory.'/current') || ($record['schema'] ?? null) !== $this->schema()) {
            throw new \RuntimeException('The source schema, ledger or current pointer has drifted. Recovery will not overwrite it.');
        }
        $files = $record['files'] ?? null;
        if (!\is_array($files)) {
            throw new \RuntimeException('Protected runtime evidence is unavailable.');
        }
        foreach ($files as $path => $evidence) {
            if (!\is_string($path) || !\is_array($evidence)) {
                throw new \RuntimeException('Protected runtime evidence is invalid.');
            }
            NativeUpdateJournal::regular($path, true === ($evidence['public'] ?? false));
            $s = stat($path);
            if (!\is_array($s) || $s['uid'] !== ($evidence['uid'] ?? null) || $s['gid'] !== ($evidence['gid'] ?? null) || $s['mode'] !== ($evidence['mode'] ?? null)) {
                throw new \RuntimeException('Protected runtime ownership or mode has changed.');
            }
            $bytes = NativeUpdateJournal::text(file_get_contents($path));
            if ($path === $this->privateDirectory.'/install.lock') {
                $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
                if (!\is_array($value) || !\in_array($value['product_version'] ?? null, ['1.0.0', '1.1.0'], true)) {
                    throw new \RuntimeException('Installation lock binding is invalid.');
                }
                $value['product_version'] = '1.0.0';
                $bytes = json_encode($value, JSON_THROW_ON_ERROR);
            }
            if (hash('sha256', $bytes) !== ($evidence['sha256'] ?? null)) {
                throw new \RuntimeException('Protected runtime or cryptographic material has changed.');
            }
        }
        if (isset($record['backup'])) {
            $backup = $record['backup'];
            if (!\is_array($backup) || ($backup['sha256'] ?? null) !== hash_file('sha256', NativeUpdateJournal::text($backup['path'] ?? null))) {
                throw new \RuntimeException('The operation-bound backup is missing or has changed.');
            }
            $this->backups->verify(NativeUpdateJournal::text($backup['path']));
        }
        if (isset($record['assets']) && $record['assets'] !== $this->assets()) {
            throw new \RuntimeException('Application assets have changed outside the operation. Retain the verified backup and investigate.');
        }
        if (isset($record['data']) && $record['data'] !== $this->data()) {
            throw new \RuntimeException('Application data has changed outside the update. Retain fencing and investigate; no data was restored.');
        }
    }

    /** @return array<string, array{sha256: string, uid: int, gid: int, mode: int, public: bool}> */
    private function captureFiles(string $directory): array
    {
        NativeUpdateJournal::directory($directory);
        $paths = [$this->runtimeConfigurationFile, $this->masterKeyFile, $this->privateDirectory.'/install.lock'];
        $values = (new \Symfony\Component\Dotenv\Dotenv())->parse(NativeUpdateJournal::text(file_get_contents($this->runtimeConfigurationFile)));
        foreach (['EMAILER_RECOVERY_KEY_FILE', 'EMAILER_RELEASE_PUBLIC_KEY_FILE'] as $name) {
            $paths[] = NativeUpdateJournal::text($values[$name] ?? null);
        }
        $result = [];
        foreach ($paths as $i => $path) {
            $public = $path === ($values['EMAILER_RELEASE_PUBLIC_KEY_FILE'] ?? null);
            NativeUpdateJournal::regular($path, $public);
            $bytes = NativeUpdateJournal::text(file_get_contents($path));
            NativeUpdateJournal::write($directory.'/file-'.$i, $bytes);
            $s = stat($path);
            if (!\is_array($s)) {
                throw new \RuntimeException('Protected runtime metadata is unavailable.');
            }
            if ($path === $this->privateDirectory.'/install.lock') {
                $bytes = json_encode(json_decode($bytes, true, 16, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR);
            }
            $result[$path] = ['sha256' => hash('sha256', $bytes), 'uid' => $s['uid'], 'gid' => $s['gid'], 'mode' => $s['mode'], 'public' => $public];
        }

        return $result;
    }

    private function schema(): string
    {
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);
        $definitions = [];
        foreach ($tables as $table) {
            $row = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteSingleIdentifier($table));
            if (false === $row) {
                throw new \RuntimeException('Schema definition is unavailable.');
            }
            foreach ($row as &$definition) {
                $definition = preg_replace('/ AUTO_INCREMENT=[0-9]+/', '', NativeUpdateJournal::text($definition));
            }
            unset($definition);
            $definitions[$table] = $row;
        }
        $triggers = $this->connection->fetchAllAssociative('SELECT TRIGGER_NAME,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_ORDER,ACTION_CONDITION,ACTION_STATEMENT,ACTION_ORIENTATION,ACTION_TIMING,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME');
        $views = $this->connection->fetchAllAssociative('SELECT TABLE_NAME,VIEW_DEFINITION,CHECK_OPTION,IS_UPDATABLE,DEFINER,SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME');
        if (false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() LIMIT 1') || false !== $this->connection->fetchOne('SELECT 1 FROM information_schema.EVENTS WHERE EVENT_SCHEMA=DATABASE() LIMIT 1')) {
            throw new \RuntimeException('This application schema contains unsupported stored routines or database events.');
        }
        $ledger = $this->connection->fetchAllAssociative('SELECT * FROM doctrine_migration_versions ORDER BY version');

        return hash('sha256', serialize([$definitions, $triggers, $views, $ledger]));
    }

    private function data(): string
    {
        $hash = hash_init('sha256');
        $tables = $this->connection->createSchemaManager()->listTableNames();
        sort($tables);
        foreach ($tables as $table) {
            if (\in_array($table, ['release_operation', 'backup_record'], true)) {
                continue;
            }
            $primary = $this->connection->createSchemaManager()->introspectTable($table)->getPrimaryKey();
            if (null === $primary) {
                throw new \RuntimeException('Application data cannot be fingerprinted without a stable primary key.');
            }
            $order = implode(',', array_map($this->connection->quoteSingleIdentifier(...), $primary->getColumns()));
            hash_update($hash, serialize($table));
            foreach ($this->connection->executeQuery('SELECT * FROM '.$this->connection->quoteSingleIdentifier($table).' ORDER BY '.$order)->iterateAssociative() as $row) {
                if ('product_identity' === $table) {
                    if (!\in_array($row['product_version'] ?? null, ['1.0.0', '1.1.0'], true)) {
                        throw new \RuntimeException('The product identity has drifted.');
                    }
                    $row['product_version'] = '1.0.0';
                }
                hash_update($hash, serialize($row));
            }
        }

        return hash_final($hash);
    }

    private function assets(): string
    {
        $roots = [];
        foreach ($this->contributors as $contributor) {
            foreach ($contributor->assetRoots() as $name => $root) {
                if (isset($roots[$name])) {
                    throw new \RuntimeException('Backup asset names are ambiguous.');
                }
                $roots[$name] = $root;
            }
        }
        ksort($roots);
        $files = [];
        foreach ($roots as $name => $root) {
            if (is_link($root) || !is_dir($root) || realpath($root) !== $root) {
                throw new \RuntimeException('An asset root is missing or linked.');
            }
            $paths = [$root];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                    throw new \RuntimeException('An asset path is unsafe.');
                }
                $paths[] = $entry->getPathname();
            }
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $stat = lstat($path);
                if (!\is_array($stat)) {
                    throw new \RuntimeException('Asset metadata is unavailable.');
                }
                $files[$name.substr($path, \strlen($root))] = [$stat['uid'], $stat['gid'], $stat['mode'], is_file($path) ? hash_file('sha256', $path) : 'directory'];
            }
        }

        return hash('sha256', serialize($files));
    }

    /** @param array<string, mixed> $manifest */
    private function installed(string $root, array $manifest): void
    {
        if (!\is_array($manifest['files'] ?? null) || is_link($root) || !is_dir($root)) {
            throw new \RuntimeException('An immutable source or target release is unavailable.');
        }
        $seen = [];
        $rootStat = lstat($root);
        if (!\is_array($rootStat) || 0 !== $rootStat['uid'] || 0 !== ($rootStat['mode'] & 0o222)) {
            throw new \RuntimeException('Immutable release root ownership is unsafe.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                throw new \RuntimeException('Immutable release contains an unsafe entry.');
            }
            if ($entry->isDir()) {
                $stat = lstat($entry->getPathname());
                if (!\is_array($stat) || 0 !== $stat['uid'] || 0 !== ($stat['mode'] & 0o222)) {
                    throw new \RuntimeException('Immutable directory ownership is unsafe.');
                }
                continue;
            }
            $name = substr($entry->getPathname(), \strlen($root) + 1);
            $s = lstat($entry->getPathname());
            if (!\is_array($s) || 0 !== $s['uid'] || 0 !== ($s['mode'] & 0o222) || ($manifest['files'][$name] ?? null) !== hash_file('sha256', $entry->getPathname())) {
                throw new \RuntimeException('Immutable release contents or ownership differ from the authenticated package.');
            }
            $seen[] = $name;
        }
        if (\count($seen) !== \count($manifest['files'])) {
            throw new \RuntimeException('Immutable release inventory is incomplete.');
        }
    }

    private function switch(string $target): void
    {
        $temporary = $this->releasesDirectory.'/.current-'.bin2hex(random_bytes(12));
        if (!symlink($target, $temporary) || !rename($temporary, $this->releasesDirectory.'/current')) {
            throw new \RuntimeException('Atomic application activation failed.');
        }
        $h = fopen($this->releasesDirectory, 'rb');
        if (false === $h) {
            throw new \RuntimeException('Release directory cannot be synchronized.');
        }
        try {
            if (!fsync($h)) {
                throw new \RuntimeException('Release activation durability failed.');
            }
        } finally {
            fclose($h);
        }
    }

    /** @param array<string, mixed> $record */
    private function save(string $path, array $record): void
    {
        NativeUpdateJournal::write($path, json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string, mixed> $record
     * @return array{name: string, host: string, locked: bool}
     */
    private function account(array $record): array
    {
        $a = $record['account'] ?? null;
        if (!\is_array($a) || !\is_bool($a['locked'] ?? null)) {
            throw new \RuntimeException('Database fencing evidence is unavailable.');
        }

        return ['name' => NativeUpdateJournal::text($a['name'] ?? null), 'host' => NativeUpdateJournal::text($a['host'] ?? null), 'locked' => $a['locked']];
    }

    /** @param array<string, mixed> $record */
    private function closeFence(array $record): void
    {
        $this->fence->close($this->account($record));
    }

    /** @param array<string, mixed> $record */
    private function restoreFence(array $record): void
    {
        $this->fence->restore($this->account($record));
    }
}
