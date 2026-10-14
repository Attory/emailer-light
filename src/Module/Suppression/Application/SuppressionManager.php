<?php

declare(strict_types=1);

namespace App\Module\Suppression\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Suppression\Application\Contract\RecipientSuppression;
use App\Module\Suppression\Application\Contract\SuppressionLookup;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class SuppressionManager implements SuppressionLookup, RecipientSuppression
{
    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
    ) {
    }

    public function isSuppressed(string $canonicalEmail): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM suppression WHERE email_canonical = ?', [$canonicalEmail]);
    }

    public function add(AdministrativeActor $actor, string $email, string $reason = 'manual', string $source = 'administration'): string
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'suppression.add');
        if (!\in_array($reason, ['manual', 'unsubscribe', 'complaint', 'hard_bounce', 'imported'], true)) {
            throw new \InvalidArgumentException('Suppression reason is invalid.');
        }
        $address = new EmailAddress($email);
        $source = trim($source);
        if ('' === $source || 64 < mb_strlen($source, 'UTF-8') || 1 === preg_match('/[\x00-\x1F\x7F]/', $source)) {
            throw new \InvalidArgumentException('Suppression source is invalid.');
        }
        $id = Identity::uuidV7();
        $this->connection->transactional(function () use ($actor, $address, $reason, $source, &$id): void {
            $this->connection->executeStatement('INSERT IGNORE INTO suppression (id, email_canonical, reason, source, created_at) VALUES (?, ?, ?, ?, ?)', [$id, $address->canonical, $reason, $source, Identity::now()]);
            $storedId = $this->connection->fetchOne('SELECT id FROM suppression WHERE email_canonical = ? FOR UPDATE', [$address->canonical]);
            if (!\is_string($storedId)) {
                throw new \UnexpectedValueException('Suppression identity is invalid.');
            }
            $id = $storedId;
            $this->audit->append(new AuditRecord($actor->id(), 'suppression.added', $id, 'success', ['reason' => $reason]));
        });

        return $id;
    }

    public function suppressFromRecipientAction(string $email): string
    {
        $address = new EmailAddress($email);
        $id = Identity::uuidV7();
        $this->connection->transactional(function () use ($address, &$id): void {
            $this->connection->executeStatement('INSERT IGNORE INTO suppression (id, email_canonical, reason, source, created_at) VALUES (?, ?, ?, ?, ?)', [$id, $address->canonical, 'unsubscribe', 'recipient_action', Identity::now()]);
            $storedId = $this->connection->fetchOne('SELECT id FROM suppression WHERE email_canonical = ? FOR UPDATE', [$address->canonical]);
            if (!\is_string($storedId)) {
                throw new \UnexpectedValueException('Suppression identity is invalid.');
            }
            $id = $storedId;
            $this->audit->append(new AuditRecord(null, 'recipient.unsubscribe', $id, 'success', ['reason' => 'unsubscribe']));
        });

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function list(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'suppression.list');
        $rows = $this->connection->fetchAllAssociative('SELECT id, email_canonical, reason, source, created_at FROM suppression ORDER BY created_at DESC, id DESC LIMIT 500');
        foreach ($rows as &$row) {
            if (!\is_string($row['email_canonical'])) {
                throw new \UnexpectedValueException('Suppression address is invalid.');
            }
            $address = new EmailAddress($row['email_canonical']);
            $row['email_display'] = $actor->roleRank() >= AdministrativeAccessLevel::Administrator->value ? $address->entered : $address->masked();
            unset($row['email_canonical']);
        }

        if ($actor->roleRank() >= AdministrativeAccessLevel::Administrator->value) {
            $this->audit->append(new AuditRecord($actor->id(), 'suppression.addresses.viewed', null, 'success', ['count' => \count($rows)]));
        }

        return $rows;
    }
}
