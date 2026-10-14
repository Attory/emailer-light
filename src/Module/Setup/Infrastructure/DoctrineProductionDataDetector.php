<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\ProductionDataDetector;
use Doctrine\DBAL\Connection;

final readonly class DoctrineProductionDataDetector implements ProductionDataDetector
{
    private const INFRASTRUCTURE_TABLES = [
        'doctrine_migration_versions',
        'messenger_messages',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function hasProductionData(): bool
    {
        foreach ($this->connection->createSchemaManager()->listTableNames() as $table) {
            if (\in_array($table, self::INFRASTRUCTURE_TABLES, true)) {
                continue;
            }

            $quotedTable = $this->connection->quoteIdentifier($table);
            $count = $this->connection->fetchOne('SELECT COUNT(*) FROM '.$quotedTable);

            if ((!\is_int($count) && !\is_string($count)) || (int) $count > 0) {
                return true;
            }
        }

        return false;
    }
}
