<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Secrets\Application\Contract\MasterKey;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\SetupProgress;
use Doctrine\DBAL\Connection;

final readonly class DoctrineSetupState implements SetupState
{
    private const STEPS = [
        'organisation' => 'organisation_ready_at',
        'administrator' => 'administrator_ready_at',
        'mail' => 'mail_ready_at',
        'policy' => 'policy_ready_at',
        'capability' => 'capability_ready_at',
    ];

    public function __construct(
        private Connection $connection,
        private MasterKey $masterKey,
        private SetupLifecycle $lifecycle,
    ) {
    }

    public function progress(): SetupProgress
    {
        if (!$this->connection->createSchemaManager()->tablesExist(['installation_state'])) {
            return new SetupProgress(null, false, false, false, false, false, false, false, false);
        }

        $state = $this->connection->fetchAssociative("SELECT * FROM installation_state WHERE singleton_key = '1'");
        if (false === $state) {
            throw new \RuntimeException('Installation state is unavailable.');
        }

        return new SetupProgress(
            \is_string($state['installation_uuid']) ? $state['installation_uuid'] : null,
            null !== $state['database_ready_at'],
            null !== $state['key_ready_at'],
            null !== $state['organisation_ready_at'],
            null !== $state['administrator_ready_at'],
            null !== $state['mail_ready_at'],
            null !== $state['policy_ready_at'],
            null !== $state['capability_ready_at'],
            null !== $state['completed_at'],
        );
    }

    public function initializeKey(): string
    {
        return $this->lifecycle->mutate(function (): string {
            $this->connection->beginTransaction();
            try {
                $state = $this->connection->fetchAssociative("SELECT installation_uuid, key_ready_at FROM installation_state WHERE singleton_key = '1' FOR UPDATE");
                if (false === $state) {
                    throw new \RuntimeException('Database setup has not completed.');
                }

                $installationId = \is_string($state['installation_uuid'])
                    ? $state['installation_uuid']
                    : self::uuidV7();

                if (null === $state['installation_uuid']) {
                    $this->connection->update('installation_state', ['installation_uuid' => $installationId], ['singleton_key' => '1']);
                }

                $this->masterKey->create();
                if (null === $state['key_ready_at']) {
                    $this->connection->update('installation_state', ['key_ready_at' => self::now()], ['singleton_key' => '1']);
                }
                $this->connection->commit();

                return $installationId;
            } catch (\Throwable $exception) {
                $this->connection->rollBack();
                throw $exception;
            }
        });
    }

    public function mark(string $step): void
    {
        $column = self::STEPS[$step] ?? null;
        if (null === $column) {
            throw new \InvalidArgumentException('Unknown setup step.');
        }

        $this->lifecycle->mutate(function () use ($column): void {
            $this->connection->executeStatement(
                \sprintf("UPDATE installation_state SET %s = COALESCE(%s, ?) WHERE singleton_key = '1'", $column, $column),
                [self::now()],
            );
        });
    }

    private static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
    }

    private static function uuidV7(): string
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $bytes = random_bytes(16);
        for ($index = 5; 0 <= $index; --$index) {
            $bytes[$index] = \chr($milliseconds & 0xFF);
            $milliseconds >>= 8;
        }
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
