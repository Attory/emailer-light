<?php

declare(strict_types=1);

namespace App\Module\Analytics\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use Doctrine\DBAL\Connection;

final readonly class AnalyticsManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private AuditSink $audit)
    {
    }

    /** @return array{policy: array<string, mixed>, campaigns: list<array<string, mixed>>} */
    public function view(AdministrativeActor $actor): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'analytics.view');
        $policy = $this->connection->fetchAssociative("SELECT open_tracking, click_tracking, tracking_retention_days, rsvp_enabled, rsvp_retention_days, rsvp_repeat_policy FROM privacy_policy WHERE singleton_key = '1'");
        if (false === $policy) {
            throw new \RuntimeException('Analytics policy is unavailable.');
        }

        return ['policy' => $policy, 'campaigns' => $this->connection->fetchAllAssociative(<<<'SQL'
SELECT c.id, c.name, s.open_tracking, s.click_tracking,
       COALESCE(a.approximate_opens, 0) approximate_opens,
       COALESCE(a.approximate_clicks, 0) approximate_clicks
FROM campaign c
INNER JOIN campaign_content_snapshot s ON s.campaign_id = c.id
LEFT JOIN (
    SELECT r.campaign_id,
           COUNT(DISTINCT CASE WHEN x.event_type = 'open' THEN x.campaign_recipient_id END) approximate_opens,
           COUNT(DISTINCT CASE WHEN x.event_type = 'click' THEN x.campaign_recipient_id END) approximate_clicks
    FROM campaign_recipient r
    INNER JOIN analytics_aggregate x ON x.campaign_recipient_id = r.id
    GROUP BY r.campaign_id
) a ON a.campaign_id = c.id
ORDER BY c.created_at DESC
LIMIT 100
SQL)];
    }

    public function configure(AdministrativeActor $actor, bool $open, bool $click, int $retentionDays, bool $rsvp, int $rsvpRetentionDays, string $rsvpRepeatPolicy): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'analytics.configure');
        if (1 > $retentionDays || 365 < $retentionDays || 1 > $rsvpRetentionDays || 365 < $rsvpRetentionDays || !\in_array($rsvpRepeatPolicy, ['first', 'replace'], true)) {
            throw new \InvalidArgumentException('Privacy policy is invalid.');
        }
        $this->connection->transactional(function () use ($actor, $open, $click, $retentionDays, $rsvp, $rsvpRetentionDays, $rsvpRepeatPolicy): void {
            if (1 !== $this->connection->update('privacy_policy', ['open_tracking' => $open ? 1 : 0, 'click_tracking' => $click ? 1 : 0, 'tracking_retention_days' => $retentionDays, 'rsvp_enabled' => $rsvp ? 1 : 0, 'rsvp_retention_days' => $rsvpRetentionDays, 'rsvp_repeat_policy' => $rsvpRepeatPolicy], ['singleton_key' => '1'])) {
                throw new \RuntimeException('Analytics policy is unavailable.');
            }
            $this->audit->append(new AuditRecord($actor->id(), 'analytics.policy.updated', null, 'success', ['open_tracking' => $open, 'click_tracking' => $click, 'retention_days' => $retentionDays, 'rsvp_enabled' => $rsvp, 'rsvp_retention_days' => $rsvpRetentionDays, 'rsvp_repeat_policy' => $rsvpRepeatPolicy, 'effective_for' => 'future_campaign_approvals']));
        });
    }
}
