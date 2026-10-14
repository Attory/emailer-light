<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;

final readonly class AuthorizationService implements AdministrativeAuthorization
{
    public function __construct(private AuditSink $audit)
    {
    }

    public function assertAtLeast(AuthenticatedUser $actor, AdministrativeRole $required, string $action, ?string $sourceHash = null): void
    {
        if (!$actor->active() || $actor->role()->rank() < $required->rank()) {
            $this->deny($actor, $action, $sourceHash);
        }
    }

    public function assertAtLeastRank(AdministrativeActor $actor, AdministrativeAccessLevel $required, string $action, ?string $sourceHash = null): void
    {
        if (!$actor->active() || $actor->roleRank() < $required->value) {
            $this->denyActor($actor, $action, $sourceHash);
        }
    }

    public function recordDenial(AuthenticatedUser $actor, string $action, ?string $sourceHash = null): void
    {
        $this->audit->append(new AuditRecord(
            $actor->id(),
            'authorization.denied',
            null,
            'denied',
            ['operation' => substr(preg_replace('/[^a-z0-9_.-]/', '_', strtolower($action)) ?? 'unknown', 0, 96)],
            $sourceHash,
        ));
    }

    private function deny(AuthenticatedUser $actor, string $action, ?string $sourceHash): never
    {
        $this->recordDenial($actor, $action, $sourceHash);

        throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Access denied.');
    }

    private function denyActor(AdministrativeActor $actor, string $action, ?string $sourceHash): never
    {
        $this->audit->append(new AuditRecord(
            $actor->id(),
            'authorization.denied',
            null,
            'denied',
            ['operation' => substr(preg_replace('/[^a-z0-9_.-]/', '_', strtolower($action)) ?? 'unknown', 0, 96)],
            $sourceHash,
        ));

        throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException('Access denied.');
    }
}
