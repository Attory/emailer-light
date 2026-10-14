<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Campaigns\Application\CampaignRecipientCsv;
use App\Module\Campaigns\Application\Contract\SendingGate;
use App\Module\Configuration\Application\Contract\DeliveryConfiguration;
use App\Module\Delivery\Application\Contract\CampaignDeliveryGateway;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Templates\Application\Contract\CampaignTemplatePreparation;
use App\Module\Templates\Application\Contract\PublishedTemplateProvider;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[CoversClass(CampaignRecipientCsv::class)]
final class CampaignRecipientCsvTest extends KernelTestCase
{
    private Connection $database;
    private string $administratorId = '01990101-0000-7000-8000-000000000001';
    private string $campaignId = '01990101-0000-7000-8000-000000000004';

    private static function numeric(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }

    protected function setUp(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $this->database = $database;
        $this->database->beginTransaction();
        $now = '2026-01-01 00:00:00.000000';
        $this->database->insert('app_user', ['id' => $this->administratorId, 'singleton_key' => '1', 'login_identifier' => 'administrator@example.test', 'password_hash' => password_hash('synthetic-only-very-long-password', PASSWORD_ARGON2ID), 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
        $this->database->insert('delivery_policy', ['singleton_key' => '1', 'messages_per_minute' => 20, 'worker_concurrency' => 1, 'retry_limit' => 3, 'maximum_campaign_size' => 100, 'maximum_csv_bytes' => 10240, 'maximum_csv_rows' => 100, 'worker_mode' => 'supervised', 'worker_available' => 1, 'scheduler_available' => 1]);
        $this->database->insert('email_template', ['id' => '01990101-0000-7000-8000-000000000002', 'name' => 'Synthetic template', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $this->database->insert('template_version', ['id' => '01990101-0000-7000-8000-000000000003', 'template_id' => '01990101-0000-7000-8000-000000000002', 'version_number' => 1, 'subject' => 'Synthetic', 'html_body' => '<p>Synthetic</p>', 'text_body' => 'Synthetic', 'merge_fields_json' => '[]', 'link_manifest_json' => '[]', 'content_sha256' => str_repeat('a', 64), 'created_at' => $now]);
        $this->database->insert('campaign', ['id' => $this->campaignId, 'name' => 'Synthetic campaign', 'template_version_id' => '01990101-0000-7000-8000-000000000003', 'state' => 'draft', 'operation_version' => 1, 'created_by' => $this->administratorId, 'created_at' => $now, 'updated_at' => $now]);
        $this->database->insert('suppression', ['id' => '01990101-0000-7000-8000-000000000005', 'email_canonical' => 'blocked@example.test', 'reason' => 'unsubscribe', 'source' => 'synthetic', 'created_at' => $now]);
    }

    protected function tearDown(): void
    {
        if ($this->database->isTransactionActive()) {
            $this->database->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testSingleAdministratorEnforcedByDatabase(): void
    {
        $this->expectException(\Doctrine\DBAL\Exception\UniqueConstraintViolationException::class);
        $this->database->insert('app_user', ['id' => '01990101-0000-7000-8000-000000000099', 'singleton_key' => '1', 'login_identifier' => 'second@example.test', 'password_hash' => password_hash('synthetic-only-very-long-password', PASSWORD_ARGON2ID), 'active' => 1, 'created_at' => '2026-01-01 00:00:00.000000', 'updated_at' => '2026-01-01 00:00:00.000000', 'password_changed_at' => '2026-01-01 00:00:00.000000']);
    }

    public function testCampaignCsvDeduplicatesReplacesDeletesAndFreezesAfterApproval(): void
    {
        $authorization = self::createStub(AdministrativeAuthorization::class);
        $audit = self::createStub(AuditSink::class);
        $audit->method('append')->willReturn('01990101-0000-7000-8000-000000000090');
        $csv = new CampaignRecipientCsv($this->database, $authorization, $audit);
        $administrator = new AuthenticatedUser($this->administratorId, 'administrator@example.test', 'synthetic-hash', AdministrativeRole::Administrator, true);
        $path = tempnam(sys_get_temp_dir(), 'light-csv-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "email,first_name\nOne@EXAMPLE.TEST,Ada\none@example.test,Duplicate\nBlocked@example.test,Blocked\ninvalid-address,Bad\ndanger@example.test,\"line\nbreak\"\n");
            self::assertSame(['total' => 5, 'imported' => 2, 'duplicate' => 1, 'invalid' => 2, 'suppressed' => 1], $csv->replace($administrator, $this->campaignId, $path));
            self::assertSame(2, $this->recipientCount());
            file_put_contents($path, "email\nnew@example.test\n");
            self::assertSame(1, $csv->replace($administrator, $this->campaignId, $path)['imported']);
            $csv->delete($administrator, $this->campaignId);
            self::assertSame(0, $this->recipientCount());
            $csv->replace($administrator, $this->campaignId, $path);
            $recipient = $this->database->fetchAssociative('SELECT recipient_id, email_canonical, profile_json FROM campaign_audience_recipient WHERE campaign_id = ?', [$this->campaignId]);
            self::assertIsArray($recipient);
            self::assertIsString($recipient['recipient_id']);
            self::assertIsString($recipient['email_canonical']);
            self::assertIsString($recipient['profile_json']);
            $digest = hash('sha256', $recipient['recipient_id']."\0".$recipient['email_canonical']."\0".$recipient['profile_json']);
            $this->database->insert('campaign_audience_snapshot', ['campaign_id' => $this->campaignId, 'recipient_count' => 1, 'audience_sha256' => $digest, 'created_at' => '2026-01-01 00:00:00.000000']);
            try {
                $csv->delete($administrator, $this->campaignId);
                self::fail('Approved audience was mutable.');
            } catch (\DomainException) {
                self::assertSame(1, $this->recipientCount());
            }
            $this->expectException(\Doctrine\DBAL\Exception\DriverException::class);
            $this->database->delete('campaign_audience_recipient', ['campaign_id' => $this->campaignId]);
        } finally {
            unlink($path);
        }
    }

    private function recipientCount(): int
    {
        $value = $this->database->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient WHERE campaign_id = ?', [$this->campaignId]);
        self::assertIsNumeric($value);

        return (int) $value;
    }

    public function testFullCampaignCreationAndApprovalUseOnlyOwnedRecipients(): void
    {
        $authorization = self::createStub(AdministrativeAuthorization::class);
        $audit = self::createStub(AuditSink::class);
        $audit->method('append')->willReturn('01990101-0000-7000-8000-000000000090');
        $recipients = new CampaignRecipientCsv($this->database, $authorization, $audit);
        $templates = self::createStub(PublishedTemplateProvider::class);
        $templates->method('get')->willReturn(['id' => '01990101-0000-7000-8000-000000000003', 'subject' => 'Synthetic', 'preheader' => null, 'html' => '<p>Synthetic</p>', 'text' => 'Synthetic', 'merge_fields' => [], 'links' => [], 'content_sha256' => str_repeat('a', 64)]);
        $preparation = self::createStub(CampaignTemplatePreparation::class);
        $configuration = self::createStub(DeliveryConfiguration::class);
        $configuration->method('current')->willReturn(['installation_id' => '01990101-0000-7000-8000-000000000010', 'host' => 'smtp.example.test', 'port' => 587, 'encryption' => 'starttls', 'username' => 'synthetic', 'credential_id' => null, 'verify_peer' => true, 'sender_address' => 'sender@example.test', 'sender_name' => 'Synthetic sender', 'reply_to' => null, 'one_click_dkim_confirmed' => false, 'unsubscribe_footer' => 'Unsubscribe', 'open_tracking' => false, 'click_tracking' => false, 'rsvp_enabled' => false, 'rsvp_retention_days' => 90, 'rsvp_repeat_policy' => 'replace', 'messages_per_minute' => 20, 'concurrency' => 1, 'retry_limit' => 3, 'max_campaign_size' => 100]);
        $manager = new CampaignManager($this->database, $authorization, $audit, $recipients, $templates, $preparation, $configuration, self::createStub(CampaignDeliveryGateway::class), self::createStub(SendingGate::class));
        $administrator = new AuthenticatedUser($this->administratorId, 'administrator@example.test', 'synthetic-hash', AdministrativeRole::Administrator, true);
        $path = tempnam(sys_get_temp_dir(), 'light-csv-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "email,first_name\nreader@example.test,Example\n");
            $id = $manager->prepareDraft($administrator, 'Synthetic newsletter', '01990101-0000-7000-8000-000000000003', null, $path, 10);
            self::assertSame(1, self::numeric($this->database->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient WHERE campaign_id = ?', [$id])));
            $manager->submitForReview($administrator, $id);
            $revision = $this->database->fetchOne('SELECT operation_version FROM campaign WHERE id = ?', [$id]);
            self::assertIsNumeric($revision);
            $manager->approve($administrator, $id, (int) $revision, true);
            self::assertSame('approved', $this->database->fetchOne('SELECT state FROM campaign WHERE id = ?', [$id]));
            self::assertSame(1, self::numeric($this->database->fetchOne('SELECT recipient_count FROM campaign_audience_snapshot WHERE campaign_id = ?', [$id])));
            $this->expectException(\DomainException::class);
            $recipients->replace($administrator, $id, $path);
        } finally {
            unlink($path);
        }
    }

    public function testRipeOnlyTablesAreAbsent(): void
    {
        $tables = $this->database->createSchemaManager()->listTableNames();
        foreach (['contact', 'contact_list', 'list_membership', 'campaign_list_selection', 'mfa_credential', 'password_reset_token'] as $removed) {
            self::assertNotContains($removed, $tables);
        }
    }
}
