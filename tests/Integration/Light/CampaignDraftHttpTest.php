<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Setup\Application\Contract\InstallationLock;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CampaignDraftHttpTest extends WebTestCase
{
    public function testIncompleteCsvDraftTemplateRoundTripFailureAndDeliveryGates(): void
    {
        $private = sys_get_temp_dir().'/light-editor-http-'.bin2hex(random_bytes(8));
        mkdir($private, 0o700);
        file_put_contents($private.'/install.lock', 'synthetic');
        chmod($private.'/install.lock', 0o600);
        $previousPrivate = $_SERVER['EMAILER_PRIVATE_DIR'] ?? null;
        $_SERVER['EMAILER_PRIVATE_DIR'] = $private;
        $client = self::createClient();
        $client->disableReboot();
        $lock = self::createStub(InstallationLock::class);
        $lock->method('exists')->willReturn(true);
        self::getContainer()->set(InstallationLock::class, $lock);
        $database = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $database);
        $database->beginTransaction();
        $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'emailer.example.test', 'REMOTE_ADDR' => '198.51.100.77'];
        try {
            $id = Identity::uuidV7();
            $now = Identity::now();
            $password = 'synthetic-editor-phrase-2026';
            $database->insert('app_user', ['id' => $id, 'singleton_key' => '1', 'login_identifier' => 'editor@example.test',
                'password_hash' => password_hash($password, PASSWORD_ARGON2ID), 'active' => 1,
                'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
            $database->insert('organisation_settings', ['singleton_key' => '1', 'installation_uuid' => $id,
                'display_name' => 'Example newsletter', 'canonical_base_url' => 'https://emailer.example.test',
                'locale' => 'en_AU', 'time_zone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
            $client->request('GET', '/admin/templates/assets', server: $server);
            self::assertTrue($client->getResponse()->isRedirection());
            $loginRedirect = $client->getResponse()->headers->get('location');
            self::assertSame('/login', $loginRedirect);
            $client->request('GET', '/', server: $server);
            self::assertResponseRedirects('/login', 303);
            $login = $client->request('GET', '/login', server: $server);
            $client->request('POST', '/login', ['login_identifier' => 'editor@example.test', 'password' => $password,
                '_token' => self::token($login, 'form input[name="_token"]')], server: $server);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            $authenticatedRedirect = $client->getResponse()->headers->get('location');
            self::assertSame('https://emailer.example.test/admin/templates/assets', $authenticatedRedirect);
            $client->request('GET', '/', server: $server);
            self::assertResponseRedirects('/admin', 303);
            $client->request('GET', '/admin', server: $server);
            self::assertResponseIsSuccessful();

            $database->insert('delivery_policy', ['singleton_key' => '1', 'messages_per_minute' => 20, 'worker_concurrency' => 1, 'retry_limit' => 3, 'maximum_campaign_size' => 100, 'maximum_csv_bytes' => 10240, 'maximum_csv_rows' => 100, 'worker_mode' => 'supervised', 'worker_available' => 1, 'scheduler_available' => 1]);
            $database->insert('privacy_policy', ['singleton_key' => '1', 'open_tracking' => 0, 'click_tracking' => 0, 'rsvp_enabled' => 0, 'operational_retention_days' => 30, 'delivery_retention_days' => 365, 'suppression_mode' => 'global', 'unsubscribe_footer' => 'Unsubscribe']);
            $source = $private.'/recipients.csv';
            file_put_contents($source, "email,first_name\nalex@example.test,Alex\n");
            $form = $client->request('GET', '/admin/campaigns', server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(0, $form->filter('[name="template_mode"], [name="html"], [name="template_file"]')->count());
            $create = ['name' => 'A retained campaign', 'messages_per_minute' => '17', 'template_version' => '', 'audience_mode' => 'upload', '_destination' => 'templates', '_token' => self::token($form, '[data-campaign-editor] [name="_token"]')];
            $client->request('POST', '/admin/campaigns/create', [...$create, 'messages_per_minute' => '999'], ['recipient_csv' => new UploadedFile($source, 'recipients.csv', 'text/csv', test: true)], server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $failure = self::responseArray($client->getResponse()->getContent());
            self::assertFalse($failure['saved']);
            self::assertStringContainsString('1–600', json_encode($failure, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM campaign')));
            $client->request('POST', '/admin/campaigns/create', $create, ['recipient_csv' => new UploadedFile($source, 'recipients.csv', 'text/csv', test: true)], server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $saved = self::responseArray($client->getResponse()->getContent());
            self::assertTrue($saved['saved']);
            self::assertIsString($saved['id']);
            $campaignId = $saved['id'];
            self::assertSame('/admin/templates?return_campaign='.$campaignId, $saved['redirect']);
            self::assertNull($database->fetchOne('SELECT template_version_id FROM campaign WHERE id = ?', [$campaignId]));
            self::assertSame('A retained campaign', $database->fetchOne('SELECT name FROM campaign WHERE id = ?', [$campaignId]));
            self::assertSame(17, self::number($database->fetchOne('SELECT messages_per_minute FROM campaign WHERE id = ?', [$campaignId])));
            self::assertSame(1, self::number($database->fetchOne('SELECT COUNT(*) FROM campaign_audience_recipient WHERE campaign_id = ?', [$campaignId])));
            $recipient = $database->fetchAssociative('SELECT * FROM campaign_audience_recipient WHERE campaign_id = ?', [$campaignId]);
            $detail = $client->request('GET', '/admin/campaigns/'.$campaignId, server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('Draft', $detail->filter('[data-campaign-state]')->text());
            $client->request('POST', '/admin/campaigns/'.$campaignId.'/review', ['_token' => self::token($detail, 'form[action$="/review"] [name="_token"]')], server: $server);
            self::assertSame('draft', $database->fetchOne('SELECT state FROM campaign WHERE id = ?', [$campaignId]));
            $templates = $client->request('GET', '/admin/templates?return_campaign='.$campaignId, server: $server);
            self::assertSame('/admin/campaigns/'.$campaignId.'/edit', $templates->filter('[data-campaign-return]')->attr('href'));
            $content = ['name' => 'A new eligible template', 'subject' => 'Hello {{ recipient.first_name }}', 'preheader' => 'A preheader', 'html_body' => '<p>Hello {{ recipient.first_name }}</p>', 'text_body' => 'Hello', 'return_campaign' => $campaignId, '_token' => self::token($templates, '[data-template-editor] [name="_token"]')];
            $client->request('POST', '/admin/templates/create', $content, server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $templateSaved = self::responseArray($client->getResponse()->getContent());
            self::assertIsString($templateSaved['redirect']);
            self::assertStringContainsString('return_campaign='.$campaignId, $templateSaved['redirect']);
            $version = $database->fetchOne('SELECT id FROM template_version');
            self::assertIsString($version);
            $editUrl = '/admin/campaigns/'.$campaignId.'/edit';
            $edit = $client->request('GET', $editUrl, server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(1, $edit->filter('option[value="'.$version.'"]')->count());
            $update = ['name' => 'A retained campaign', 'messages_per_minute' => '17', 'template_version' => $version, '_token' => self::token($edit, '[data-campaign-editor] [name="_token"]')];
            $client->request('POST', $editUrl, $update, server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame($version, $database->fetchOne('SELECT template_version_id FROM campaign WHERE id = ?', [$campaignId]));
            self::assertNull($database->fetchOne('SELECT published_at FROM template_version WHERE id = ?', [$version]));
            self::assertSame($recipient, $database->fetchAssociative('SELECT * FROM campaign_audience_recipient WHERE campaign_id = ?', [$campaignId]));
            $client->request('POST', $editUrl, [...$update, 'name' => 'Still entered after failure', 'messages_per_minute' => 'bad'], server: $server);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            $retained = $client->getCrawler();
            self::assertSame('Still entered after failure', $retained->filter('[name="name"]')->attr('value'));
            self::assertSame('bad', $retained->filter('[name="messages_per_minute"]')->attr('value'));
            self::assertSame($recipient, $database->fetchAssociative('SELECT * FROM campaign_audience_recipient WHERE campaign_id = ?', [$campaignId]));
            // A failure after the CSV SQL writes must roll back both draft metadata and recipient identities.
            $services = self::getContainer();
            $realManager = $services->get(\App\Module\Campaigns\Application\CampaignManager::class);
            $realAudit = $services->get(\App\Module\Audit\Application\Contract\AuditSink::class);
            self::assertInstanceOf(\App\Module\Audit\Application\Contract\AuditSink::class, $realAudit);
            $failAudit = new class($realAudit) implements \App\Module\Audit\Application\Contract\AuditSink {
                public function __construct(private readonly \App\Module\Audit\Application\Contract\AuditSink $inner)
                {
                }

                public function append(\App\Module\Audit\Application\Contract\AuditRecord $record): string
                {
                    if ('campaign.draft.saved' === $record->action) {
                        throw new \RuntimeException('Synthetic failure after CSV writes.');
                    }

                    return $this->inner->append($record);
                }
            };
            $reflection = new \ReflectionClass(\App\Module\Campaigns\Application\CampaignManager::class);
            $constructor = $reflection->getConstructor();
            self::assertNotNull($constructor);
            $arguments = [];
            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = 'audit' === $parameter->getName() ? $failAudit : $reflection->getProperty($parameter->getName())->getValue($realManager);
            }
            $faultManager = $reflection->newInstanceArgs($arguments);
            self::assertInstanceOf(\App\Module\Campaigns\Application\CampaignManager::class, $faultManager);
            $provider = $services->get(\App\Module\Identity\Infrastructure\DoctrineUserProvider::class);
            self::assertInstanceOf(\App\Module\Identity\Infrastructure\DoctrineUserProvider::class, $provider);
            $actor = $provider->loadUserByIdentifier('editor@example.test');
            self::assertInstanceOf(\App\Module\Identity\Application\AuthenticatedUser::class, $actor);
            $beforeDraft = $database->fetchAssociative('SELECT * FROM campaign WHERE id=?', [$campaignId]);
            $beforeRecipients = $database->fetchAllAssociative('SELECT * FROM campaign_audience_recipient WHERE campaign_id=? ORDER BY recipient_id', [$campaignId]);
            $beforeAudit = $database->fetchAllAssociative('SELECT * FROM audit_event ORDER BY id');
            file_put_contents($source, "email,first_name\nretry@example.test,Retry\n");
            try {
                $faultManager->saveDraft($actor, $campaignId, 'Unconfirmed changed name', $version, 29, $source);
                self::fail('Post-CSV fault was not exercised.');
            } catch (\RuntimeException $failure) {
                self::assertSame('Synthetic failure after CSV writes.', $failure->getMessage());
            }
            self::assertSame($beforeDraft, $database->fetchAssociative('SELECT * FROM campaign WHERE id=?', [$campaignId]));
            self::assertSame($beforeRecipients, $database->fetchAllAssociative('SELECT * FROM campaign_audience_recipient WHERE campaign_id=? ORDER BY recipient_id', [$campaignId]));
            self::assertSame($beforeAudit, $database->fetchAllAssociative('SELECT * FROM audit_event ORDER BY id'));
            self::assertInstanceOf(\App\Module\Campaigns\Application\CampaignManager::class, $realManager);
            $retry = $realManager->saveDraft($actor, $campaignId, 'Retried campaign draft', $version, 29, $source);
            self::assertSame($campaignId, $retry['id']);
            self::assertSame(1, $retry['csv_counts']['imported'] ?? null);
            // A published version remains selectable while a newer template draft is being prepared.
            $database->beginTransaction();
            try {
                $templatesService = $services->get(\App\Module\Templates\Application\TemplateManager::class);
                self::assertInstanceOf(\App\Module\Templates\Application\TemplateManager::class, $templatesService);
                $templateId = $database->fetchOne('SELECT template_id FROM template_version WHERE id=?', [$version]);
                self::assertIsString($templateId);
                $templatesService->publish($actor, $templateId, $version); // Explicit, separately identified fixture action.
                $newVersion = $templatesService->addVersion($actor, $templateId, 'A newer unpublished subject', '', '<p>A newer template draft</p>', 'A newer draft');
                $choices = $templatesService->campaignOptions($actor);
                self::assertContains($version, array_column($choices, 'id'));
                self::assertContains($newVersion, array_column($choices, 'id'));
                $realManager->saveDraft($actor, $campaignId, 'Keep published selection', $version, 29, null);
                self::assertSame($version, $database->fetchOne('SELECT published_version_id FROM email_template WHERE id=?', [$templateId]));
                $realManager->saveDraft($actor, $campaignId, 'Select unpublished content', $newVersion, 29, null);
                self::assertNull($database->fetchOne('SELECT published_at FROM template_version WHERE id=?', [$newVersion]));
                try {
                    $realManager->submitForReview($actor, $campaignId);
                    self::fail('An unpublished template was eligible for review.');
                } catch (\InvalidArgumentException $failure) {
                    self::assertStringContainsString('Published template version is unavailable', $failure->getMessage());
                }
            } finally {
                $database->rollBack();
            }
            foreach (['campaign_content_snapshot', 'campaign_audience_snapshot', 'campaign_test_delivery', 'messenger_messages', 'delivery_attempt'] as $table) {
                self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM '.$table)));
            }
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
            if (null === $previousPrivate) {
                unset($_SERVER['EMAILER_PRIVATE_DIR']);
            } else {
                $_SERVER['EMAILER_PRIVATE_DIR'] = $previousPrivate;
            }
            (new Filesystem())->remove($private);
        }
    }

    private static function token(Crawler $crawler, string $selector): string
    {
        $value = $crawler->filter($selector)->attr('value');
        self::assertIsString($value);

        return $value;
    }

    /** @return array<array-key, mixed> */
    private static function responseArray(string|false $content): array
    {
        self::assertIsString($content);
        $value = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($value);

        return $value;
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
