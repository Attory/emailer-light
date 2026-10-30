<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

/** One authenticated nullable-column migration. Historical native updates keep their exact original path. */
final readonly class CampaignDraftSchemaPlan
{
    public const SOURCE_SCHEMA = '20261014000000';
    public const TARGET_SCHEMA = '20261025000000';
    public const MIGRATION = 'DoctrineMigrations\\Version20261025000000';
    public const FILE = 'migrations/Version20261025000000.php';
    public const FORWARD = 'ALTER TABLE campaign MODIFY template_version_id CHAR(36) DEFAULT NULL';
    public const INVERSE = 'ALTER TABLE campaign MODIFY template_version_id CHAR(36) NOT NULL';

    public function __construct(private Connection $connection, private string $projectDirectory, private string $targetVersion = '1.2.6')
    {
        if (!\in_array($targetVersion, ['1.2.6', '1.2.7', '1.2.8', '1.2.9', '1.2.10'], true)) {
            throw new \InvalidArgumentException('The draft schema target is unsupported.');
        }
    }

    /** @param array<string, mixed> $source
     * @param array<string, mixed> $target
     */
    public function assertPackages(array $source, array $target): void
    {
        $before = $source['files'] ?? null;
        $after = $target['files'] ?? null;
        if (!\is_array($before) || !\is_array($after) || self::SOURCE_SCHEMA !== ($source['schema_version'] ?? null) || self::TARGET_SCHEMA !== ($target['schema_version'] ?? null) || $this->targetVersion !== ($target['version'] ?? null)) {
            throw new \RuntimeException('The incomplete-draft schema plan does not match these authenticated packages.');
        }
        $old = $new = [];
        foreach ($before as $name => $hash) {
            if (\is_string($name) && str_starts_with($name, 'migrations/')) {
                $old[$name] = $hash;
            }
        }
        foreach ($after as $name => $hash) {
            if (\is_string($name) && str_starts_with($name, 'migrations/')) {
                $new[$name] = $hash;
            }
        }
        $newHash = $new[self::FILE] ?? null;
        unset($new[self::FILE]);
        ksort($old);
        ksort($new);
        if ($old !== $new || $newHash !== hash_file('sha256', $this->projectDirectory.'/'.self::FILE)) {
            throw new \RuntimeException('This update may add only the exact reviewed incomplete-draft migration; original migrations must be unchanged.');
        }
        $proof = NativeUpdateJournal::read($this->projectDirectory.'/config/upgrade/native-schema-1.2.6.json', true);
        if (($proof['format'] ?? null) !== 'emailer-light-draft-schema-v1' || ($proof['migration_sha256'] ?? null) !== $newHash || ($proof['forward_sql'] ?? null) !== self::FORWARD || ($proof['inverse_sql'] ?? null) !== self::INVERSE) {
            throw new \RuntimeException('The signed incomplete-draft schema and recovery proof is unavailable.');
        }
    }

    /** @param array{definitions: array<string, array<string, string>>, triggers: list<array<string, mixed>>, views: list<array<string, mixed>>, ledger: list<array<string, mixed>>} $parts
     * @return array<string, mixed>
     */
    public function prepare(array $parts): array
    {
        $ledger = $parts['ledger'];
        if (1 !== \count($ledger) || 'DoctrineMigrations\\Version20261014000000' !== ($ledger[0]['version'] ?? null)) {
            throw new \RuntimeException('The source migration ledger is not the exact supported Light baseline.');
        }
        $ddl = $parts['definitions']['campaign']['Create Table'] ?? '';
        $nullable = str_replace('`template_version_id` char(36) NOT NULL,', '`template_version_id` char(36) DEFAULT NULL,', $ddl, $changes);
        if (1 !== $changes) {
            throw new \RuntimeException('The exact campaign column definition could not be bound to this migration.');
        }
        // DATETIME has second precision. SELECT uses the actual current PDO/DBAL scalar types.
        $row = $this->connection->fetchAssociative('SELECT ? AS version, CAST(? AS DATETIME) AS executed_at, CAST(0 AS SIGNED) AS execution_time', [self::MIGRATION, gmdate('Y-m-d H:i:s')]);
        if (false === $row || array_keys($row) !== array_keys($ledger[0])) {
            throw new \RuntimeException('The planned migration row cannot match this database driver’s exact ledger representation.');
        }
        $states = ['source' => self::hash($parts)];
        $parts['definitions']['campaign']['Create Table'] = $nullable;
        $states['ddl_applied'] = self::hash($parts);
        $parts['ledger'][] = $row;
        $states['target'] = self::hash($parts);

        return ['format' => 'emailer-light-draft-plan-v1', 'state' => 'source', 'publication' => 'source', 'target_version' => $this->targetVersion, 'source_version' => $this->connection->fetchOne("SELECT product_version FROM product_identity WHERE singleton_key='1'"), 'schema_hashes' => $states, 'planned_ledger' => $row, 'source_ledger' => $ledger];
    }

    /** @param array{definitions: array<string, array<string, string>>, triggers: list<array<string, mixed>>, views: list<array<string, mixed>>, ledger: list<array<string, mixed>>} $parts */
    public static function hash(array $parts): string
    {
        return hash('sha256', serialize([$parts['definitions'], $parts['triggers'], $parts['views'], $parts['ledger']]));
    }

    /** A phase admits complete schema/ledger/identity tuples, never independently interchangeable fields.
     * @param array<array-key, mixed> $plan
     */
    public function assertState(array $plan, string $schemaHash): void
    {
        if ('emailer-light-draft-plan-v1' !== ($plan['format'] ?? null) || ($plan['target_version'] ?? '1.2.6') !== $this->targetVersion || !\is_array($plan['schema_hashes'] ?? null)) {
            throw new \RuntimeException('The bounded migration intent is invalid.');
        }
        $hashes = $plan['schema_hashes'];
        $state = array_search($schemaHash, $hashes, true);
        $allowed = match ($plan['state'] ?? null) {
            'source', 'source_recovered' => ['source'],
            'forward_intent', 'reverse_intent' => ['source', 'ddl_applied', 'target'],
            'target' => ['target'],
            default => [],
        };
        $identity = $this->connection->fetchOne("SELECT schema_version FROM product_identity WHERE singleton_key='1'");
        $version = $this->connection->fetchOne("SELECT product_version FROM product_identity WHERE singleton_key='1'");
        $sourceVersion = $plan['source_version'] ?? null;
        $publication = $plan['publication'] ?? null;
        $allowedVersions = \in_array($publication, ['forward_intent', 'reverse_intent'], true) ? [$sourceVersion, $this->targetVersion] : [$sourceVersion];
        if (!\is_string($sourceVersion) || !NativeSchemaUpdate::supports($sourceVersion, $this->targetVersion) || !\in_array($version, $allowedVersions, true) || ('forward_intent' === $publication && 'target' !== $state) || !\in_array($publication, ['source', 'forward_intent', 'reverse_intent', 'restored'], true)) {
            throw new \RuntimeException('The installation version is outside the recorded schema/publication phase. Recovery will not repair metadata drift.');
        }
        if (!\is_string($state) || !\in_array($state, $allowed, true) || $identity !== ('target' === $state ? self::TARGET_SCHEMA : self::SOURCE_SCHEMA)) {
            throw new \RuntimeException('The live schema, complete ledger and installation identity are outside the recorded migration phase. Keep delivery held and investigate; recovery will not overwrite drift.');
        }
        $proof = NativeUpdateJournal::read($this->projectDirectory.'/config/upgrade/'.('source' === $state ? 'native-schema-1.0.0.json' : 'native-schema-1.2.6.json'), true);
        if (($proof['definition'] ?? null) !== NativeSchemaProof::capture($this->connection)) {
            throw new \RuntimeException('The live schema does not match its complete signed source or target definition.');
        }
    }

    /** Bind file/database version tuples to durable publication/recovery intent.
     * @param array<array-key, mixed> $plan
     */
    public function assertLock(array $plan, string $bytes): void
    {
        $lock = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        $source = $plan['source_version'] ?? null;
        $sourceLock = \is_array($lock) && ($lock['product_version'] ?? null) === $source && ($lock['schema_version'] ?? null) === self::SOURCE_SCHEMA;
        $targetLock = \is_array($lock) && ($lock['product_version'] ?? null) === $this->targetVersion && ($lock['schema_version'] ?? null) === self::TARGET_SCHEMA;
        $version = $this->connection->fetchOne("SELECT product_version FROM product_identity WHERE singleton_key='1'");
        $valid = match ($plan['publication'] ?? null) {
            'source', 'restored' => $sourceLock && $version === $source,
            'forward_intent' => $sourceLock || ($targetLock && $this->targetVersion === $version),
            'reverse_intent' => $sourceLock || ($targetLock && true === ($plan['reverse_target_lock'] ?? false)),
            default => false,
        };
        if (!$valid) {
            throw new \RuntimeException('The installation lock and database version are outside the recorded publication/recovery intent. No drift was overwritten.');
        }
    }

    /** @param array<array-key, mixed> $plan
     * @param callable(array<array-key, mixed>): void $persist
     *
     * @return array<array-key, mixed>
     */
    public function forward(array $plan, callable $persist): array
    {
        $this->assertRoot();
        $plan['state'] = 'forward_intent';
        $persist($plan);
        // Use the same connection holding the database advisory lock. Orphaned in-server DDL retains that session’s lock.
        $this->connection->executeStatement(self::FORWARD);
        $row = $plan['planned_ledger'] ?? null;
        if (!\is_array($row)) {
            throw new \RuntimeException('The recorded migration row is unavailable.');
        }
        $data = [];
        foreach ($row as $key => $value) {
            if (!\is_string($key)) {
                throw new \RuntimeException('The migration ledger intent has an invalid column.');
            }
            $data[$key] = $value;
        }
        $this->connection->transactional(function () use ($data): void {
            $this->connection->insert('doctrine_migration_versions', $data);
            $this->connection->update('product_identity', ['schema_version' => self::TARGET_SCHEMA], ['singleton_key' => '1']);
        });
        $plan['state'] = 'target';
        $persist($plan);

        return $plan;
    }

    /** Caller has already authenticated the entire frozen source dataset, keys, files and exact allowed schema tuple.
     * @param array<array-key, mixed>                 $plan
     * @param callable(array<array-key, mixed>): void $persist
     *
     * @return array<array-key, mixed>
     */
    public function reverse(array $plan, callable $persist): array
    {
        $this->assertRoot();
        if (false !== $this->connection->fetchOne('SELECT 1 FROM campaign WHERE template_version_id IS NULL LIMIT 1')) {
            throw new \RuntimeException('New incomplete campaign data prevents source-schema recovery. Nothing was removed or coerced. Keep delivery held and recover in the matching signed release.');
        }
        $plan['state'] = 'reverse_intent';
        $plan['publication'] = 'reverse_intent';
        $persist($plan);
        $this->connection->transactional(function (): void {
            $this->connection->delete('doctrine_migration_versions', ['version' => self::MIGRATION]);
            $this->connection->update('product_identity', ['schema_version' => self::SOURCE_SCHEMA], ['singleton_key' => '1']);
        });
        // LIFO recovery: remove the exact planned ledger first. Never coerce/delete a NULL recipient/template record.
        $this->connection->executeStatement(self::INVERSE);
        $plan['state'] = 'source_recovered';
        $persist($plan);

        return $plan;
    }

    private function assertRoot(): void
    {
        if (0 !== posix_geteuid()) {
            throw new \RuntimeException('Database schema changes require the root-controlled guided installer.');
        }
    }
}
