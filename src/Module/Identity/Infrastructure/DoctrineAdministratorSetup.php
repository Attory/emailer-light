<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure;

use App\Module\Identity\Application\AdministratorPasswordSubject;
use App\Module\Identity\Application\Contract\AdministratorSetup;
use App\Module\Identity\Application\IdentityUtil;
use App\Module\Identity\Application\PasswordPolicy;
use App\Module\Setup\Application\Contract\SetupLifecycle;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class DoctrineAdministratorSetup implements AdministratorSetup
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $passwordHasher,
        private SetupLifecycle $lifecycle,
        private PasswordPolicy $passwordPolicy,
    ) {
    }

    public function create(string $loginIdentifier, string $password): string
    {
        $login = IdentityUtil::normalizeLogin($loginIdentifier);
        $this->passwordPolicy->assertAcceptable($password, $login);

        return $this->lifecycle->mutate(function () use ($login, $password): string {
            return $this->connection->transactional(function (Connection $connection) use ($login, $password): string {
                $existing = $connection->fetchAllAssociative('SELECT id, login_identifier, password_hash FROM app_user FOR UPDATE');
                if ([] !== $existing) {
                    if (1 !== \count($existing) || $login !== $existing[0]['login_identifier']
                        || !\is_string($existing[0]['id']) || !\is_string($existing[0]['password_hash'])
                        || !password_verify($password, $existing[0]['password_hash'])) {
                        throw new \RuntimeException('An administrator already exists; a second account cannot be created.');
                    }

                    return $existing[0]['id'];
                }

                $hash = $this->passwordHasher->hashPassword(new AdministratorPasswordSubject(), $password);
                if (!str_starts_with($hash, '$argon2id$')) {
                    throw new \RuntimeException('Argon2id password hashing is unavailable.');
                }
                $id = IdentityUtil::uuidV7();
                $now = IdentityUtil::now();
                $connection->insert('app_user', [
                    'id' => $id,
                    'singleton_key' => '1',
                    'login_identifier' => $login,
                    'password_hash' => $hash,
                    'active' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                    'password_changed_at' => $now,
                ]);

                return $id;
            });
        });
    }
}
