<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

use Doctrine\DBAL\Connection;

/** Canonical DDL generated from the unchanged public Light baseline on each supported server. */
final class NativeSchemaProof
{
    /** @return array{tables: array<string, string>, triggers: array<string, string>, views: array<string, string>} */
    public static function capture(Connection $connection): array
    {
        $result = ['tables' => [], 'triggers' => [], 'views' => []];
        foreach ($connection->createSchemaManager()->listTableNames() as $table) {
            $row = $connection->fetchAssociative('SHOW CREATE TABLE '.$connection->quoteSingleIdentifier($table));
            if (false === $row) {
                throw new \RuntimeException('Canonical table proof is unavailable.');
            }
            $ddl = self::sql(NativeUpdateJournal::text($row['Create Table'] ?? null));
            if ('doctrine_migration_versions' === $table) {
                // Doctrine creates its ASCII version ledger using the database
                // default. These three tested UTF-8 defaults leave its structure
                // unchanged; the operation still binds the exact live DDL.
                $ddl = preg_replace('/COLLATE=utf8mb4_(?:general_ci|unicode_ci|bin)\\b/', 'COLLATE=metadata_utf8mb4_default', $ddl) ?? $ddl;
            }
            $result['tables'][$table] = $ddl;
        }
        foreach ($connection->fetchFirstColumn('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME') as $name) {
            $name = NativeUpdateJournal::text($name);
            $row = $connection->fetchAssociative('SHOW CREATE TRIGGER '.$connection->quoteSingleIdentifier($name));
            if (false === $row) {
                throw new \RuntimeException('Canonical trigger proof is unavailable.');
            }
            $result['triggers'][$name] = self::sql(NativeUpdateJournal::text($row['SQL Original Statement'] ?? null));
        }
        foreach ($connection->fetchAllAssociative('SELECT TABLE_NAME,VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME') as $view) {
            $result['views'][NativeUpdateJournal::text($view['TABLE_NAME'])] = self::sql(NativeUpdateJournal::text($view['VIEW_DEFINITION']));
        }
        foreach ($result as &$map) {
            ksort($map, SORT_STRING);
        }
        unset($map);

        return $result;
    }

    private static function sql(string $sql): string
    {
        // Definer identities and row counters are host-local; all executable SQL,
        // constraints, keys, defaults and generated expressions remain in the proof.
        $sql = preg_replace('/\\bDEFINER=`(?:``|[^`])+`@`(?:``|[^`])+`\\s*| AUTO_INCREMENT=[0-9]+/', '', $sql);
        if (null === $sql) {
            throw new \RuntimeException('Canonical DDL normalization failed.');
        }

        return trim($sql);
    }
}
