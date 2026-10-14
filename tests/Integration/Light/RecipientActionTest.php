<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Configuration\Application\Contract\RecipientRuntimeConfiguration;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\RecipientActions\Application\RecipientAnalyticsService;
use App\Module\RecipientActions\Application\RecipientTokenCodec;
use App\Module\RecipientActions\Application\RecipientTokenService;
use App\Module\Secrets\Application\Contract\SecretVault;
use App\Module\Suppression\Application\SuppressionManager;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecipientActionTest extends KernelTestCase
{
    private Connection $database;

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
        $database->beginTransaction();
        $now = '2026-01-01 00:00:00.000000';
        $database->insert('email_template', ['id' => self::id(1), 'name' => 'Synthetic', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now]);
        $database->insert('template_version', ['id' => self::id(2), 'template_id' => self::id(1), 'version_number' => 1, 'subject' => 'Synthetic', 'html_body' => '<p>Synthetic</p>', 'text_body' => 'Synthetic', 'merge_fields_json' => '[]', 'link_manifest_json' => '[]', 'content_sha256' => str_repeat('a', 64), 'created_at' => $now]);
        $database->insert('campaign', ['id' => self::id(3), 'name' => 'Synthetic campaign', 'template_version_id' => self::id(2), 'state' => 'approved', 'operation_version' => 2, 'created_at' => $now, 'updated_at' => $now]);
        $database->insert('campaign_audience_recipient', ['campaign_id' => self::id(3), 'recipient_id' => self::id(4), 'email_canonical' => 'recipient@example.test', 'profile_json' => '{}']);
        $database->insert('campaign_recipient', ['id' => self::id(5), 'campaign_id' => self::id(3), 'recipient_id' => self::id(4), 'state' => 'accepted', 'attempt_count' => 1, 'message_id' => 'synthetic@example.invalid', 'operation_version' => 2, 'updated_at' => $now]);
        $database->insert('encrypted_secret', ['id' => self::id(6), 'record_type' => 'recipient.token.key', 'envelope' => 'synthetic', 'key_version' => 1, 'schema_version' => 1, 'created_at' => $now]);
        $database->insert('recipient_token_key', ['key_version' => 1, 'secret_id' => self::id(6), 'active' => 1, 'created_at' => $now]);
        $database->insert('recipient_token_key_state', ['singleton_key' => '1', 'current_version' => 1, 'updated_at' => $now]);
    }

    protected function tearDown(): void
    {
        if ($this->database->isTransactionActive()) {
            $this->database->rollBack();
        }
        self::ensureKernelShutdown();
    }

    public function testUnsubscribeSuppressesGloballyAndTrackingTokensBindToOwnedRecipient(): void
    {
        $runtime = self::createStub(RecipientRuntimeConfiguration::class);
        $runtime->method('current')->willReturn(['installation_id' => self::id(9), 'canonical_origin' => 'https://light.example.test']);
        $vault = self::createStub(SecretVault::class);
        $vault->method('reveal')->willReturn(sodium_bin2base64(str_repeat('k', 32), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING));
        $audit = self::createStub(AuditSink::class);
        $audit->method('append')->willReturn(self::id(10));
        $suppressions = new SuppressionManager($this->database, self::createStub(AdministrativeAuthorization::class), $audit);
        $codec = new RecipientTokenCodec();
        $unsubscribe = new RecipientTokenService($this->database, $runtime, $vault, $suppressions, $codec, self::createStub(AdministrativeAuthorization::class), $audit);
        $analytics = new RecipientAnalyticsService($this->database, $runtime, $vault, $codec, $unsubscribe);

        $url = $unsubscribe->issue(self::id(3), self::id(5), self::id(4), 'recipient@example.test');
        $token = explode('/', $url)[4];
        $unsubscribe->inspect($token);
        $unsubscribe->unsubscribe($token);
        self::assertTrue($suppressions->isSuppressed('recipient@example.test'));
        self::assertSame('unsubscribe', $this->database->fetchOne("SELECT reason FROM suppression WHERE email_canonical = 'recipient@example.test'"));

        $links = $analytics->issue(self::id(3), self::id(5), self::id(4), 'recipient@example.test', true, true);
        self::assertIsString($links['open']);
        $openToken = explode('/', $links['open'])[4];
        self::assertSame(self::id(4), $analytics->verify($openToken, 'open')['recipient_id']);
        $analytics->record(self::id(5), 'open');
        self::assertSame(1, self::numeric($this->database->fetchOne("SELECT event_count FROM analytics_aggregate WHERE campaign_recipient_id = ? AND event_type = 'open'", [self::id(5)])));
    }

    private static function id(int $suffix): string
    {
        return \sprintf('01990101-0000-7000-8000-%012d', $suffix);
    }
}
