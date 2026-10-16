<?php

declare(strict_types=1);

// Run only against a newly created disposable acceptance database.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$kernel = new App\Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$database = $container->get(Doctrine\DBAL\Connection::class);
if (0 !== (int) $database->fetchOne('SELECT COUNT(*) FROM app_user')) {
    throw new RuntimeException('Guided acceptance requires a fresh disposable database.');
}
$lock = $container->get(App\Module\Setup\Application\Contract\InstallationLock::class);
if ($lock->exists()) {
    throw new RuntimeException('Guided acceptance requires fresh private state.');
}
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$tester = new Symfony\Component\Console\Tester\CommandTester($application->find('emailer:setup:install'));
$inputs = ['guided-admin@example.test', 'synthetic guided installer password 2026', 'synthetic guided installer password 2026'];
if (in_array('--retry-input', $argv, true)) {
    $inputs = ['not-an-email', 'guided-admin@example.test', 'short', 'short', 'synthetic guided installer password 2026', 'synthetic mismatched confirmation', 'synthetic guided installer password 2026', 'synthetic guided installer password 2026'];
}
$tester->setInputs($inputs);
if (0 !== $tester->execute(['--guided' => true])) {
    // Output is deliberately bounded and contains no hidden inputs.
    throw new RuntimeException('Guided setup did not complete. Its transcript is not printed because hidden input protection is under test.');
}
foreach (['synthetic guided installer password 2026', 'local-only'] as $secret) {
    if (str_contains($tester->getDisplay(), $secret)) {
        throw new RuntimeException('Hidden setup input was echoed.');
    }
}
if (!$lock->exists() || 0 !== (int) $database->fetchOne('SELECT COUNT(*) FROM sender_identity')) {
    throw new RuntimeException('Deferred SMTP was not represented safely.');
}
$row = $database->fetchAssociative('SELECT * FROM app_user');
if (!is_array($row) || !password_verify('synthetic guided installer password 2026', $row['password_hash'])) {
    throw new RuntimeException('Administrator login hash is invalid.');
}
$configuration = $container->get(App\Module\Configuration\Application\Contract\DeliveryConfiguration::class)->current();
if ('Not configured' !== $configuration['sender_address'] || null !== $configuration['credential_id']) {
    throw new RuntimeException('Draft configuration is unavailable without SMTP.');
}
$findings = $container->get(App\Module\Configuration\Infrastructure\ConfigurationReadinessProbe::class)->findings();
if (!in_array('sender_identity', array_column($findings, 'code'), true)) {
    throw new RuntimeException('Unconfigured sending was not blocked.');
}
$before = [$row['id'], $row['password_hash'], $database->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'")];
$repeat = new Symfony\Component\Console\Tester\CommandTester($application->find('emailer:setup:install'));
if (0 === $repeat->execute(['--guided' => true], ['interactive' => false])) {
    throw new RuntimeException('Completed setup was not protected.');
}
$row = $database->fetchAssociative('SELECT * FROM app_user');
$after = [$row['id'], $row['password_hash'], $database->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key = '1'")];
if ($before !== $after || 0 !== (int) $database->fetchOne('SELECT COUNT(*) FROM messenger_messages')) {
    throw new RuntimeException('Repeated setup changed identity or queued mail.');
}
echo "PASS: guided setup accepts only email/password/confirmation, locks setup, preserves account on repeat, and leaves SMTP unconfigured with sending blocked and no queued mail.\n";
