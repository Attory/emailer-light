<?php

declare(strict_types=1);

// Test-only fixture; never accepts a deployment path, database or provider.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Module\Configuration\Application\MailConfigurationManager;
use App\Module\Identity\Infrastructure\DoctrineUserProvider;
use Doctrine\DBAL\Connection;

try {
    if ('test' !== getenv('APP_ENV') || '1' !== getenv('EMAILER_BROWSER_SMTP_FIXTURE') || 'https://emailer.example.test' !== getenv('DEFAULT_URI')) {
        throw new RuntimeException('Not the disposable browser profile.');
    }
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $services = $kernel->getContainer()->get('test.service_container');
    $db = $services->get(Connection::class);
    if ('emailer_browser_test' !== $db->getDatabase() || 'database' !== ($db->getParams()['host'] ?? null)) {
        throw new RuntimeException('Not the disposable browser database.');
    }
    $input = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $config = json_decode((string) file_get_contents('/emailer-browser-secrets/browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $actor = $services->get(DoctrineUserProvider::class)->loadUserByIdentifier($config['email']);
    if ('prepare' === $input['action']) {
        // Real encrypted credential storage and normal authorization. No probe,
        // campaign approval or worker invocation occurs here.
        $services->get(MailConfigurationManager::class)->configureSecureSmtp($actor, 'smtp-fixture.test', 2465, 'tls', 'synthetic', 'synthetic-acceptance-only', 'editor-sender@example.test', 'Example newsletter', '');
        $db->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
        $snapshot = [];
        foreach (['campaign_test_delivery', 'delivery_attempt', 'messenger_messages'] as $table) {
            $snapshot[$table] = (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        file_put_contents('/emailer-browser-secrets/mail-before.json', json_encode($snapshot, JSON_THROW_ON_ERROR));
        chmod('/emailer-browser-secrets/mail-before.json', 0o600);
    } elseif (in_array($input['action'], ['reject_auth', 'delay_auth'], true)) {
        $path = '/emailer-browser-secrets/smtp/evidence/'.('reject_auth' === $input['action'] ? 'reject-auth' : 'delay-auth');
        if (true === ($input['enabled'] ?? $input['reject'] ?? false)) {
            file_put_contents($path, 'reject');
        } elseif (is_file($path)) {
            unlink($path);
        }
    } elseif ('verify' === $input['action']) {
        $snapshot = json_decode((string) file_get_contents('/emailer-browser-secrets/mail-before.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach ($snapshot as $table => $count) {
            if ($count !== (int) $db->fetchOne('SELECT COUNT(*) FROM '.$table)) {
                throw new RuntimeException('Connection testing created delivery work.');
            }
        }
        if (0 !== (int) $db->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")) {
            throw new RuntimeException('Connection testing removed the delivery hold.');
        }
    } elseif ('inspect' !== $input['action']) {
        throw new RuntimeException('Unknown bounded fixture action.');
    }
    $row = $db->fetchAssociative("SELECT smtp_host, smtp_port, smtp_username, test_message_at FROM sender_identity WHERE singleton_key='1'");
    echo json_encode(['status' => 'PASS', 'host' => $row['smtp_host'], 'port' => (int) $row['smtp_port'], 'username' => $row['smtp_username'], 'tested' => is_string($row['test_message_at']) && !str_starts_with($row['test_message_at'], '1970-01-01'), 'held' => 0 === (int) $db->fetchOne("SELECT accepting_work FROM delivery_runtime WHERE singleton_key='1'")], JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) {
    fwrite(STDERR, "Bounded SMTP browser fixture failed; no credentials exported.\n");
    exit(1);
}
