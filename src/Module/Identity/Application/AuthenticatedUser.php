<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Identity\Application\Contract\AdministrativeActor;
use Symfony\Component\Security\Core\User\EquatableInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class AuthenticatedUser implements UserInterface, PasswordAuthenticatedUserInterface, EquatableInterface, AdministrativeActor
{
    private string $passwordFingerprint;

    public function __construct(
        private string $id,
        private string $loginIdentifier,
        private string $passwordHash,
        private AdministrativeRole $role,
        private bool $active,
    ) {
        if ('' === $this->id || '' === $this->loginIdentifier) {
            throw new \InvalidArgumentException('Authenticated user identity is invalid.');
        }
        $this->passwordFingerprint = hash('sha256', $passwordHash);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function role(): AdministrativeRole
    {
        return $this->role;
    }

    public function roleRank(): int
    {
        return $this->role->rank();
    }

    public function active(): bool
    {
        return $this->active;
    }

    public function loginIdentifier(): string
    {
        return $this->getUserIdentifier();
    }

    /** @return non-empty-string */
    public function getUserIdentifier(): string
    {
        if ('' === $this->loginIdentifier) {
            throw new \LogicException('Authenticated user identity is invalid.');
        }

        return $this->loginIdentifier;
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return [$this->role->value];
    }

    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function eraseCredentials(): void
    {
    }

    public function isEqualTo(UserInterface $user): bool
    {
        return $user instanceof self
            && hash_equals($this->id, $user->id)
            && hash_equals($this->loginIdentifier, $user->loginIdentifier)
            && hash_equals($this->passwordFingerprint, $user->passwordFingerprint)
            && $this->active === $user->active;
    }

    /** @return array{id: string, login: string, role: string, active: bool, password_fingerprint: string} */
    public function __serialize(): array
    {
        return [
            'id' => $this->id,
            'login' => $this->loginIdentifier,
            'role' => $this->role->value,
            'active' => $this->active,
            'password_fingerprint' => $this->passwordFingerprint,
        ];
    }

    /** @param array{id: string, login: string, role: string, active: bool, password_fingerprint: string} $data */
    public function __unserialize(array $data): void
    {
        $this->id = $data['id'];
        $this->loginIdentifier = $data['login'];
        $this->passwordHash = '';
        $this->role = AdministrativeRole::from($data['role']);
        $this->active = $data['active'];
        $this->passwordFingerprint = $data['password_fingerprint'];
    }
}
