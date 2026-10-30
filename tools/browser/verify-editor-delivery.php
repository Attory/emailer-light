<?php

declare(strict_types=1);

// Test-only acceptance: all mail goes to the isolated Mailpit service. The
// explicit test stage is separate from upload/save no-send assertions.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Module\Campaigns\Application\CampaignManager;
use App\Module\Identity\Infrastructure\DoctrineUserProvider;
use App\Module\Templates\Application\TemplateManager;
use Doctrine\DBAL\Connection;

$phase = 'guard';
try {
    if ('test' !== getenv('APP_ENV') || 'https://emailer.example.test' !== getenv('DEFAULT_URI')) {
        throw new RuntimeException('Not the synthetic profile.');
    }
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $database = $container->get(Connection::class);
    if ('emailer_browser_test' !== $database->getDatabase() || 'database' !== ($database->getParams()['host'] ?? null)) {
        throw new RuntimeException('Not the disposable database.');
    }
    $ensure = static function (bool $condition): void {
        if (!$condition) {
            throw new RuntimeException('Synthetic acceptance assertion failed.');
        }
    };
    $read = static fn (string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $write = static function (string $file, array $data): void {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($file, 0o600);
    };
    $mail = static fn (): array => json_decode(file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000'), true, flags: JSON_THROW_ON_ERROR);
    $counts = static function () use ($database): array {
        $counts = [];
        foreach (['campaign', 'campaign_recipient', 'campaign_test_delivery', 'delivery_attempt', 'messenger_messages'] as $table) {
            $counts[$table] = (int) $database->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        $counts['published_templates'] = (int) $database->fetchOne('SELECT COUNT(*) FROM email_template WHERE published_version_id IS NOT NULL');

        return $counts;
    };
    $directory = '/emailer-browser-secrets';
    $mode = $argv[1] ?? '';
    if ('snapshot' === $mode) {
        $phase = 'no-send baseline';
        $write($directory.'/editor-no-send-baseline.json', ['counts' => $counts(), 'mail_count' => $mail()['total']]);
        echo "Synthetic no-send baseline recorded.\n";
        exit(0);
    }
    $baseline = $read($directory.'/editor-no-send-baseline.json');
    if (in_array($mode, ['actions', 'mfa'], true)) {
        $ensure($counts() === $baseline['counts'] && $mail()['total'] === $baseline['mail_count']);
        $write($directory.'/'.$mode.'-no-send-result.json', ['status' => 'PASS', 'counts_unchanged' => true, 'sink_messages_unchanged' => true]);
        echo "Focused browser no-send checks PASS.\n";
        exit(0);
    }
    $browser = $read($directory.'/browser-result.json');
    $ensure('PASS' === $browser['status']);
    if ('request' === $mode) {
        $phase = 'upload/save no-send verification';
        $ensure($counts() === $baseline['counts'] && $mail()['total'] === $baseline['mail_count']);
        $template = $database->fetchAssociative('SELECT published_version_id FROM email_template WHERE id = ?', [$browser['templateId']]);
        $ensure(false !== $template && null === $template['published_version_id']);
        $version = $database->fetchAssociative('SELECT id, html_body FROM template_version WHERE template_id = ? ORDER BY version_number DESC LIMIT 1', [$browser['templateId']]);
        $ensure(false !== $version && str_contains($version['html_body'], $browser['assetPath']));
        $asset = $database->fetchAssociative('SELECT content_sha256 FROM template_asset WHERE filename = ?', [basename($browser['assetPath'])]);
        $ensure(false !== $asset && $asset['content_sha256'] === $browser['publicImageSha256']);
        $write($directory.'/editor-no-send-result.json', ['status' => 'PASS', 'baseline_counts' => $baseline['counts'], 'final_counts' => $counts(), 'mail_count_unchanged' => true, 'uploaded_image_public_bytes_match' => true]);
        $phase = 'explicit synthetic campaign test request';
        $credentials = $read($directory.'/browser-fixture.json');
        $actor = $container->get(DoctrineUserProvider::class)->loadUserByIdentifier($credentials['email']);
        // This deliberate publication is solely for the separately identified
        // campaign test. Uploading and saving above published nothing.
        $container->get(TemplateManager::class)->publish($actor, $browser['templateId'], $version['id']);
        $campaigns = $container->get(CampaignManager::class);
        $campaign = $campaigns->create($actor, '[TEST] Image editor isolated delivery', $version['id']);
        $test = $campaigns->requestTest($actor, $campaign);
        $write($directory.'/editor-explicit-test.json', ['campaign' => $campaign, 'test' => $test, 'mail_before' => $mail()['total'], 'asset' => $browser['assetPath']]);
        echo "Upload/save no-send assertions PASS; one explicit synthetic campaign test queued.\n";
        exit(0);
    }
    if ('verify' !== $mode) {
        throw new RuntimeException('Unknown test mode.');
    }
    $phase = 'actual worker SMTP capture';
    $test = $read($directory.'/editor-explicit-test.json');
    $ensure('accepted' === $database->fetchOne('SELECT state FROM campaign_test_delivery WHERE id = ?', [$test['test']]));
    $campaign = $database->fetchAssociative('SELECT state, approved_at, approved_by FROM campaign WHERE id = ?', [$test['campaign']]);
    $ensure(false !== $campaign && 'draft' === $campaign['state'] && null === $campaign['approved_at'] && null === $campaign['approved_by']);
    $ensure(0 === (int) $database->fetchOne('SELECT COUNT(*) FROM campaign_recipient') && 0 === (int) $database->fetchOne('SELECT COUNT(*) FROM delivery_attempt'));
    $captured = $mail();
    $ensure($test['mail_before'] + 1 === $captured['total']);
    foreach ($captured['messages'] as $message) {
        $detail = $read('http://mailpit:8025/api/v1/message/'.rawurlencode($message['ID']));
        if (!str_contains($detail['HTML'] ?? '', 'https://emailer.example.test'.$test['asset'])) {
            continue;
        }
        $ensure(!str_contains($detail['HTML'], 'src="'.$test['asset']));
        $raw = file_get_contents('http://mailpit:8025/api/v1/message/'.rawurlencode($message['ID']).'/raw');
        $ensure(false !== $raw && str_contains($raw, 'MIME-Version: 1.0'));
        file_put_contents($directory.'/editor-explicit-test.eml', $raw);
        chmod($directory.'/editor-explicit-test.eml', 0o600);
        $write($directory.'/editor-delivery-result.json', ['status' => 'PASS', 'worker_test_state' => 'accepted', 'absolute_https_image_url' => 'https://emailer.example.test'.$test['asset'], 'message_sha256' => hash('sha256', $raw), 'captured_messages_delta' => 1, 'campaign_state' => 'draft', 'approved' => false, 'newsletter_delivery_attempts' => 0, 'no_send_before_explicit_test' => true]);
        echo "Actual queued SMTP message captured; canonical HTTPS image and no-newsletter assertions PASS.\n";
        exit(0);
    }
    throw new RuntimeException('The isolated message did not contain the uploaded image.');
} catch (Throwable) {
    fwrite(STDERR, 'Editor delivery acceptance failed at '.$phase.". No secrets printed.\n");
    exit(1);
}
