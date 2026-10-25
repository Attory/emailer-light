<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Doctrine\DBAL\Connection;

$phase = 'isolated fixture guard';
try {
    if ('test' !== getenv('APP_ENV') || 'https://emailer.example.test' !== getenv('DEFAULT_URI')) {
        throw new RuntimeException('Not the synthetic profile.');
    }
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $database = $kernel->getContainer()->get('test.service_container')->get(Connection::class);
    if ('emailer_browser_test' !== $database->getDatabase() || 'database' !== ($database->getParams()['host'] ?? null)) {
        throw new RuntimeException('Not the disposable database.');
    }
    $ensure = static function (bool $value): void {
        if (!$value) {
            throw new RuntimeException('Campaign workflow acceptance failed.');
        }
    };
    $read = static fn (string $file): array => json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
    $write = static function (string $file, array $data): void {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($file, 0o600);
    };
    $counts = static function () use ($database): array {
        $result = [];
        foreach (['campaign_test_delivery', 'campaign_recipient', 'campaign_content_snapshot', 'campaign_audience_snapshot', 'delivery_attempt', 'messenger_messages'] as $table) {
            $result[$table] = (int) $database->fetchOne('SELECT COUNT(*) FROM '.$table);
        }
        $result['published_templates'] = (int) $database->fetchOne('SELECT COUNT(*) FROM template_version WHERE published_at IS NOT NULL');
        $result['mail'] = json_decode(file_get_contents('http://mailpit:8025/api/v1/messages?limit=1000'), true, flags: JSON_THROW_ON_ERROR)['total'];

        return $result;
    };
    $directory = '/emailer-browser-secrets';
    if ('snapshot' === ($argv[1] ?? '')) {
        $write($directory.'/campaign-baseline.json', ['counts' => $counts(), 'campaigns' => $database->fetchFirstColumn('SELECT id FROM campaign')]);
        exit(0);
    }
    $phase = 'no publication approval or delivery';
    $baseline = $read($directory.'/campaign-baseline.json');
    $browser = $read($directory.'/campaign-browser-result.json');
    $ensure('PASS' === $browser['status'] && $counts() === $baseline['counts']);
    $new = array_values(array_diff($database->fetchFirstColumn('SELECT id FROM campaign'), $baseline['campaigns']));
    $ensure($new === $browser['campaigns'] && 1 === count($new));
    $row = $database->fetchAssociative('SELECT * FROM campaign WHERE id = ?', [$new[0]]);
    $ensure(false !== $row && 'Autumn newsletter' === $row['name'] && 19 === (int) $row['messages_per_minute'] && 'draft' === $row['state'] && null === $row['approved_at'] && $row['template_version_id'] === $browser['selectedTemplate']);
    $phase = 'durable campaign-owned CSV';
    $addresses = $database->fetchFirstColumn('SELECT email_canonical FROM campaign_audience_recipient WHERE campaign_id = ? ORDER BY email_canonical', [$new[0]]);
    $ensure(['alex@example.test', 'pat@example.test'] === $addresses);
    $ensure(0 === (int) $database->fetchOne('SELECT COUNT(*) FROM template_version WHERE id = ? AND published_at IS NOT NULL', [$browser['selectedTemplate']]));
    $write($directory.'/campaign-no-send-result.json', ['status' => 'PASS', 'campaigns_created' => 1, 'campaign_owned_recipients' => 2, 'preserved_name_rate_and_template' => true, 'no_additional_publication_approval_queue_or_mail' => true, 'baseline_counts' => $baseline['counts'], 'final_counts' => $counts()]);
    echo "Campaign navigation, durable CSV and no-unintended-send assertions PASS.\n";
} catch (Throwable) {
    fwrite(STDERR, 'Campaign workflow verification failed at '.$phase.". Private request details omitted.\n");
    exit(1);
}
