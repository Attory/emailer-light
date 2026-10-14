<?php

declare(strict_types=1);

namespace App\Module\Campaigns\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Shared\EmailAddress;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

/** Campaign-scoped CSV import. No reusable contact or list record is created. */
final readonly class CampaignRecipientCsv
{
    private const COLUMNS = ['email', 'display_name', 'first_name', 'last_name', 'locale', 'time_zone'];

    public function __construct(
        private Connection $connection,
        private AdministrativeAuthorization $authorization,
        private AuditSink $audit,
    ) {
    }

    /** @return array{total: int, imported: int, duplicate: int, invalid: int, suppressed: int} */
    public function replace(AdministrativeActor $actor, string $campaignId, string $path): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'campaign.recipients.replace');
        if (!is_file($path) || is_link($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Recipient CSV is unavailable.');
        }
        $policy = $this->connection->fetchAssociative("SELECT maximum_csv_bytes, maximum_csv_rows, maximum_campaign_size FROM delivery_policy WHERE singleton_key = '1'");
        if (false === $policy) {
            throw new \RuntimeException('CSV limits are unavailable.');
        }
        $size = filesize($path);
        if (false === $size || $size < 1 || $size > (int) (is_numeric($policy['maximum_csv_bytes']) ? $policy['maximum_csv_bytes'] : 0)) {
            throw new \InvalidArgumentException('Recipient CSV exceeds the configured size limit.');
        }
        $stream = fopen($path, 'rb');
        if (false === $stream) {
            throw new \RuntimeException('Recipient CSV could not be opened.');
        }
        $counts = ['total' => 0, 'imported' => 0, 'duplicate' => 0, 'invalid' => 0, 'suppressed' => 0];
        $recipients = [];
        try {
            $header = fgetcsv($stream, 16_385, ',', '"', '');
            if (!\is_array($header)) {
                throw new \InvalidArgumentException('Recipient CSV requires a header row.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0] ?? '') ?? '';
            $header = array_map(static fn (?string $value): string => strtolower(trim($value ?? '')), $header);
            if (\count($header) > 32 || \count(array_unique($header)) !== \count($header) || !\in_array('email', $header, true)) {
                throw new \InvalidArgumentException('Recipient CSV header is invalid.');
            }
            foreach ($header as $name) {
                if (!\in_array($name, self::COLUMNS, true)) {
                    throw new \InvalidArgumentException('Recipient CSV has an unsupported column.');
                }
            }
            $columns = array_flip($header);
            while (false !== ($row = fgetcsv($stream, 16_385, ',', '"', ''))) {
                ++$counts['total'];
                if ($counts['total'] > (int) (is_numeric($policy['maximum_csv_rows']) ? $policy['maximum_csv_rows'] : 0) || $counts['total'] > 100_000) {
                    throw new \InvalidArgumentException('Recipient CSV exceeds the configured row limit.');
                }
                if (\count($row) !== \count($header) || !$this->validRow($row)) {
                    ++$counts['invalid'];
                    continue;
                }
                try {
                    $address = new EmailAddress($row[$columns['email']] ?? '');
                    $profile = [];
                    foreach (['display_name' => 160, 'first_name' => 100, 'last_name' => 100, 'locale' => 20, 'time_zone' => 64] as $field => $limit) {
                        $value = isset($columns[$field]) ? trim($row[$columns[$field]] ?? '') : '';
                        if (mb_strlen($value, 'UTF-8') > $limit) {
                            throw new \InvalidArgumentException('Recipient profile value is too long.');
                        }
                        $profile[$field] = '' === $value ? null : $value;
                    }
                    if (isset($recipients[$address->canonical])) {
                        ++$counts['duplicate'];
                        continue;
                    }
                    $recipients[$address->canonical] = $profile;
                } catch (\InvalidArgumentException) {
                    ++$counts['invalid'];
                }
            }
            if (!feof($stream) || 0 === $counts['total'] || [] === $recipients) {
                throw new \InvalidArgumentException('Recipient CSV contains no valid recipient rows.');
            }
            if (\count($recipients) > (int) (is_numeric($policy['maximum_campaign_size']) ? $policy['maximum_campaign_size'] : 0)) {
                throw new \InvalidArgumentException('Recipient CSV exceeds the campaign size limit.');
            }
        } finally {
            fclose($stream);
        }
        $counts['imported'] = \count($recipients);
        $this->connection->transactional(function (Connection $connection) use ($actor, $campaignId, $recipients, &$counts): void {
            $this->assertEditable($campaignId);
            $connection->delete('campaign_audience_recipient', ['campaign_id' => $campaignId]);
            foreach ($recipients as $email => $profile) {
                $connection->insert('campaign_audience_recipient', [
                    'campaign_id' => $campaignId,
                    'recipient_id' => Identity::uuidV7(),
                    'email_canonical' => $email,
                    'profile_json' => json_encode($profile, JSON_THROW_ON_ERROR),
                ]);
            }
            $suppressed = $connection->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient a INNER JOIN suppression s ON s.email_canonical = a.email_canonical WHERE a.campaign_id = ?', [$campaignId]);
            $counts['suppressed'] = is_numeric($suppressed) ? (int) $suppressed : 0;
            $connection->executeStatement("UPDATE campaign SET state = 'draft', operation_version = operation_version + 1, updated_at = ? WHERE id = ?", [Identity::now(), $campaignId]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.recipients.replaced', $campaignId, 'success', $counts));
        });

        return $counts;
    }

    public function delete(AdministrativeActor $actor, string $campaignId): void
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'campaign.recipients.delete');
        $this->connection->transactional(function (Connection $connection) use ($actor, $campaignId): void {
            $this->assertEditable($campaignId);
            $count = $connection->delete('campaign_audience_recipient', ['campaign_id' => $campaignId]);
            $connection->executeStatement("UPDATE campaign SET state = 'draft', operation_version = operation_version + 1, updated_at = ? WHERE id = ?", [Identity::now(), $campaignId]);
            $this->audit->append(new AuditRecord($actor->id(), 'campaign.recipients.deleted', $campaignId, 'success', ['recipient_count' => $count]));
        });
    }

    private function assertEditable(string $campaignId): void
    {
        $campaign = $this->connection->fetchAssociative('SELECT state FROM campaign WHERE id = ? FOR UPDATE', [$campaignId]);
        if (false === $campaign || !\in_array($campaign['state'], ['draft', 'review'], true)
            || false !== $this->connection->fetchOne('SELECT 1 FROM campaign_audience_snapshot WHERE campaign_id = ?', [$campaignId])) {
            throw new \DomainException('Recipients are immutable after campaign approval.');
        }
    }

    /** @param list<string|null> $row */
    private function validRow(array $row): bool
    {
        foreach ($row as $value) {
            if (!\is_string($value) || \strlen($value) > 500 || !mb_check_encoding($value, 'UTF-8')
                || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
                return false;
            }
        }

        return true;
    }
}
