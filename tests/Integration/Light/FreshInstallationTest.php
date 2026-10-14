<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Operations\Application\OperationsPolicy;
use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use App\Module\Setup\Infrastructure\DoctrineSetupState;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class FreshInstallationTest extends KernelTestCase
{
    public function testCleanMigrationSeedsEveryRequiredSetupAndRuntimeSingleton(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $database->beginTransaction();
        try {
            $masterKey = new class implements MasterKey {
                public function create(): void
                {
                }

                public function load(): string
                {
                    return str_repeat('k', 32);
                }

                public function exists(): bool
                {
                    return true;
                }

                public function recover(string $key): void
                {
                }

                public function replace(string $expectedCurrentKey, string $replacementKey): void
                {
                }
            };
            $lifecycle = new class implements SetupLifecycle {
                public function mutate(\Closure $operation): mixed
                {
                    return $operation();
                }

                public function finalize(\Closure $operation): mixed
                {
                    return $operation();
                }
            };
            $setup = new DoctrineSetupState($database, $masterKey, $lifecycle);
            self::assertTrue($setup->progress()->databaseReady);
            $installationId = $setup->initializeKey();
            self::assertSame($installationId, $setup->progress()->installationId);
            self::assertTrue($setup->progress()->keyReady);
            $tokenVersion = $database->fetchOne("SELECT current_version FROM recipient_token_key_state WHERE singleton_key = '1'");
            self::assertIsNumeric($tokenVersion);
            self::assertSame(0, (int) $tokenVersion);
            self::assertSame('none', (new OperationsPolicy($database))->current()['backup_destination_type']);
            self::assertSame('emailer-light', $database->fetchOne("SELECT product_id FROM product_identity WHERE singleton_key = '1'"));
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
        }
    }
}
