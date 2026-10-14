<?php

declare(strict_types=1);

namespace App\Module\Audit\Infrastructure;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\IdentityUtil;
use Doctrine\DBAL\Connection;

final readonly class DoctrineAuditSink implements AuditSink
{
    private const SAFE_KEY = '/^[a-z][a-z0-9_.-]{0,63}$/';
    private const SENSITIVE_KEY = '/(?:password|secret|token|session|cookie|authorization|totp|recovery|credential|code)/i';

    public function __construct(private Connection $connection)
    {
    }

    public function append(AuditRecord $record): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_.-]{2,95}$/', $record->action)
            || !\in_array($record->result, ['success', 'failure', 'denied'], true)
            || (null !== $record->actorUserId && 1 !== preg_match('/^[0-9a-f-]{36}$/', $record->actorUserId))
            || (null !== $record->targetId && 1 !== preg_match('/^[a-zA-Z0-9_.:-]{1,64}$/', $record->targetId))
            || (null !== $record->sourceHash && 1 !== preg_match('/^[a-f0-9]{64}$/', $record->sourceHash))
        ) {
            throw new \InvalidArgumentException('Audit event shape is invalid.');
        }

        $summary = [];
        foreach ($record->summary as $key => $value) {
            if (1 !== preg_match(self::SAFE_KEY, $key) || 1 === preg_match(self::SENSITIVE_KEY, $key)) {
                throw new \InvalidArgumentException('Audit summary contains a prohibited field.');
            }
            if (\is_string($value)) {
                if (160 < \strlen($value) || str_contains($value, '@') || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
                    throw new \InvalidArgumentException('Audit summary contains unsafe text.');
                }
            }
            $summary[$key] = $value;
        }

        $id = IdentityUtil::uuidV7();
        $this->connection->insert('audit_event', [
            'id' => $id,
            'actor_user_id' => $record->actorUserId,
            'action' => $record->action,
            'target_id' => $record->targetId,
            'occurred_at' => IdentityUtil::now(),
            'source_hash' => $record->sourceHash,
            'result' => $record->result,
            'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
