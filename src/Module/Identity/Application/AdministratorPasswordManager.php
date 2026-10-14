<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use Doctrine\DBAL\Connection;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class AdministratorPasswordManager
{
    public function __construct(
        private Connection $connection,
        private UserPasswordHasherInterface $hasher,
        private PasswordPolicy $policy,
        private SessionRegistry $sessions,
        private AuditSink $audit,
    ) {
    }

    public function change(AuthenticatedUser $actor, string $currentPassword, string $newPassword, string $currentSessionId): AuthenticatedUser
    {
        if (!$actor->active() || !password_verify($currentPassword, $actor->getPassword())) {
            throw new \RuntimeException('Password change was not accepted.');
        }
        $this->policy->assertAcceptable($newPassword, $actor->getUserIdentifier());
        $hash = $this->hash($newPassword);

        return $this->connection->transactional(function (Connection $connection) use ($actor, $hash, $currentSessionId): AuthenticatedUser {
            $row = $connection->fetchAssociative("SELECT id, password_hash FROM app_user WHERE singleton_key = '1' AND active = 1 FOR UPDATE");
            $administratorCount = $connection->fetchOne('SELECT COUNT(*) FROM app_user');
            if (false === $row || $row['id'] !== $actor->id() || !\is_string($row['password_hash'])
                || !hash_equals($row['password_hash'], $actor->getPassword()) || 1 !== (is_numeric($administratorCount) ? (int) $administratorCount : 0)) {
                throw new \RuntimeException('Administrator state changed; password change was not accepted.');
            }
            $now = IdentityUtil::now();
            $connection->update('app_user', ['password_hash' => $hash, 'password_changed_at' => $now, 'updated_at' => $now], ['id' => $actor->id()]);
            $this->sessions->revokeAll($actor->id(), $currentSessionId);
            $this->audit->append(new AuditRecord($actor->id(), 'identity.password.changed', $actor->id(), 'success', ['revocation_scope' => 'other']));

            return new AuthenticatedUser($actor->id(), $actor->getUserIdentifier(), $hash, AdministrativeRole::Administrator, true);
        });
    }

    /** Local protected CLI recovery only; the caller must obtain the password from hidden interactive input. */
    public function recover(string $newPassword): void
    {
        $this->connection->transactional(function (Connection $connection) use ($newPassword): void {
            $row = $connection->fetchAssociative("SELECT id, login_identifier FROM app_user WHERE singleton_key = '1' AND active = 1 FOR UPDATE");
            $administratorCount = $connection->fetchOne('SELECT COUNT(*) FROM app_user');
            if (false === $row || !\is_string($row['id']) || !\is_string($row['login_identifier'])
                || 1 !== (is_numeric($administratorCount) ? (int) $administratorCount : 0)) {
                throw new \RuntimeException('Exactly one active administrator is required for recovery.');
            }
            $this->policy->assertAcceptable($newPassword, $row['login_identifier']);
            $hash = $this->hash($newPassword);
            $now = IdentityUtil::now();
            $connection->update('app_user', ['password_hash' => $hash, 'password_changed_at' => $now, 'updated_at' => $now], ['id' => $row['id']]);
            $this->sessions->revokeAll($row['id']);
            $this->audit->append(new AuditRecord($row['id'], 'identity.password.cli_recovered', $row['id'], 'success', ['revocation_scope' => 'all']));
        });
    }

    private function hash(string $password): string
    {
        $hash = $this->hasher->hashPassword(new AdministratorPasswordSubject(), $password);
        if (!str_starts_with($hash, '$argon2id$')) {
            throw new \RuntimeException('Argon2id password hashing is unavailable.');
        }

        return $hash;
    }
}
