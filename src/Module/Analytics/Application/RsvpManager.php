<?php

declare(strict_types=1);

namespace App\Module\Analytics\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use Doctrine\DBAL\Connection;

final readonly class RsvpManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private AuditSink $audit)
    {
    }

    /** @return array{policy: array<string, mixed>, campaigns: list<array<string, mixed>>} */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'rsvp.view');
        $policy = $this->connection->fetchAssociative("SELECT rsvp_enabled, rsvp_retention_days, rsvp_repeat_policy FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $policy) {
            throw new \RuntimeException('RSVP policy is unavailable.');
        }
        $campaigns = $this->connection->fetchAllAssociative(<<<'SQL'
SELECT c.id, c.name, s.rsvp_enabled, s.rsvp_repeat_policy,
       COALESCE(a.recipient_count, 0) invited,
       COUNT(rr.campaign_recipient_id) responded,
       COALESCE(SUM(rr.response_choice = 'yes'), 0) yes_count,
       COALESCE(SUM(rr.response_choice = 'no'), 0) no_count,
       COALESCE(SUM(rr.response_choice = 'maybe'), 0) maybe_count,
       COALESCE(SUM(rr.guest_count), 0) guest_count
FROM campaign c
INNER JOIN campaign_content_snapshot s ON s.campaign_id = c.id
LEFT JOIN campaign_audience_snapshot a ON a.campaign_id = c.id
LEFT JOIN campaign_recipient r ON r.campaign_id = c.id
LEFT JOIN rsvp_response rr ON rr.campaign_recipient_id = r.id
GROUP BY c.id, c.name, s.rsvp_enabled, s.rsvp_repeat_policy, a.recipient_count, c.created_at
ORDER BY c.created_at DESC
LIMIT 100
SQL);

        return ['policy' => $policy, 'campaigns' => $campaigns];
    }

    /** @param resource $stream */
    public function export(AdministrativeActor $actor, string $campaignId, $stream): int
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'rsvp.export');
        $this->uuid($campaignId);
        if (false === $this->connection->fetchOne('SELECT 1 FROM campaign_content_snapshot WHERE campaign_id = ? AND rsvp_enabled = 1', [$campaignId])) {
            throw new \DomainException('RSVP export is unavailable.');
        }
        $this->writeRow($stream, ['campaign_recipient_id', 'response', 'guests', 'note', 'first_submitted_at', 'last_submitted_at', 'submission_count']);
        $count = 0;
        $result = $this->connection->executeQuery('SELECT r.id, rr.response_choice, rr.guest_count, rr.note, rr.first_submitted_at, rr.last_submitted_at, rr.submission_count FROM campaign_recipient r INNER JOIN rsvp_response rr ON rr.campaign_recipient_id = r.id WHERE r.campaign_id = ? ORDER BY rr.first_submitted_at, r.id', [$campaignId]);
        while (false !== ($row = $result->fetchNumeric())) {
            $this->writeRow($stream, array_map($this->neutralize(...), $row));
            ++$count;
        }
        $this->audit->append(new AuditRecord($actor->id(), 'rsvp.exported', $campaignId, 'success', ['row_count' => $count, 'recipient_identity' => 'opaque']));

        return $count;
    }

    private function neutralize(mixed $value): string
    {
        if (null === $value) {
            $value = '';
        } elseif (\is_scalar($value)) {
            $value = (string) $value;
        } else {
            throw new \UnexpectedValueException('RSVP export value is invalid.');
        }
        if ('' !== $value && (str_contains('=+-@', $value[0]) || "\t" === $value[0] || "\r" === $value[0])) {
            return "'".$value;
        }

        return $value;
    }

    /** @param resource $stream
     * @param list<string> $row
     */
    private function writeRow($stream, array $row): void
    {
        if (false === fputcsv($stream, $row, ',', '"', '')) {
            throw new \RuntimeException('RSVP export could not be written.');
        }
    }

    private function uuid(string $id): void
    {
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Campaign identity is invalid.');
        }
    }
}
