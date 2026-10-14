<?php

declare(strict_types=1);

namespace App\Module\Audit\Application;

use Doctrine\DBAL\Connection;

final readonly class AuditReader
{
    public function __construct(private Connection $connection)
    {
    }

    /** @return list<array{id: string, actor_user_id: ?string, action: string, target_id: ?string, occurred_at: string, result: string, summary: array<string, mixed>}> */
    public function latest(int $limit = 100): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, actor_user_id, action, target_id, occurred_at, result, summary_json FROM audit_event ORDER BY occurred_at DESC, id DESC LIMIT '.max(1, min(100, $limit)),
        );
        $result = [];
        foreach ($rows as $row) {
            if (!\is_string($row['id'] ?? null)
                || !\is_string($row['action'] ?? null)
                || !\is_string($row['occurred_at'] ?? null)
                || !\is_string($row['result'] ?? null)
                || !\is_string($row['summary_json'] ?? null)
            ) {
                continue;
            }
            $decoded = json_decode($row['summary_json'], true, 16, JSON_THROW_ON_ERROR);
            $summary = [];
            if (\is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (\is_string($key)) {
                        $summary[$key] = $value;
                    }
                }
            }
            $result[] = [
                'id' => $row['id'],
                'actor_user_id' => \is_string($row['actor_user_id'] ?? null) ? $row['actor_user_id'] : null,
                'action' => $row['action'],
                'target_id' => \is_string($row['target_id'] ?? null) ? $row['target_id'] : null,
                'occurred_at' => $row['occurred_at'],
                'result' => $row['result'],
                'summary' => $summary,
            ];
        }

        return $result;
    }
}
