<?php

declare(strict_types=1);

// Execute only on the owned internal acceptance network, against the synthetic TLS sink.
require is_file(dirname(__DIR__, 2).'/vendor/autoload.php') ? dirname(__DIR__, 2).'/vendor/autoload.php' : '/opt/emailer-vendor/autoload.php';
foreach (['Application/Contract/MailProbe', 'Application/Contract/MailTestFailure', 'Infrastructure/SmtpFailureClassifier', 'Infrastructure/SmtpProbeTransport', 'Infrastructure/SmtpMailProbe'] as $component) {
    require_once dirname(__DIR__, 2).'/src/Module/Configuration/'.$component.'.php';
}

use App\Module\Configuration\Application\Contract\MailTestFailure;
use App\Module\Configuration\Infrastructure\SmtpMailProbe;

$probe = new SmtpMailProbe('prod');
$host = 'smtp-fixture.test';
$password = 'synthetic-acceptance-only';
$probe->check($host, 2465, 'tls', 'synthetic', $password, 'sender@example.test', null);
$probe->check($host, 2525, 'starttls', 'synthetic', $password, 'sender@example.test', null);
if (file_exists('/evidence/state.json')) {
    throw new RuntimeException('A connection-only test transmitted DATA.');
}
foreach ([[2465, 'tls'], [2525, 'starttls']] as [$port, $mode]) {
    foreach ([['wrong-host.test', $password], [$host, 'synthetic-rejected-password']] as [$invalidHost, $invalidPassword]) {
        try {
            $probe->check($invalidHost, $port, $mode, 'synthetic', $invalidPassword, 'sender@example.test', null);
            throw new LogicException('An invalid TLS identity or password was accepted.');
        } catch (MailTestFailure $error) {
            $expected = $invalidHost !== $host ? 'tls_certificate' : 'authentication';
            if ($expected !== $error->category) {
                throw new LogicException('The transport failure category was incorrect: '.$error->category);
            }
            if (str_contains(json_encode($error->safeDiagnostics(), JSON_THROW_ON_ERROR).$error->getMessage(), $invalidPassword)) {
                throw new LogicException('A transport failure exposed a credential.');
            }
        }
    }
}
foreach ([['missing-name.invalid', 2465, 'dns'], [$host, 2499, 'connection']] as [$invalidHost, $invalidPort, $category]) {
    try {
        $probe->check($invalidHost, $invalidPort, 'tls', 'synthetic', $password, '', null);
        throw new LogicException('An unavailable endpoint was accepted.');
    } catch (MailTestFailure $error) {
        if ($category !== $error->category) {
            throw new LogicException('The endpoint failure category was incorrect: '.$error->category);
        }
    }
}
if (file_exists('/evidence/state.json')) {
    throw new RuntimeException('An invalid configuration transmitted DATA.');
}
$probe->check($host, 2525, 'starttls', 'synthetic', $password, 'sender@example.test', 'requested-test@example.test');
$evidence = json_decode((string) file_get_contents('/evidence/state.json'), true, 8, JSON_THROW_ON_ERROR);
if (1 !== $evidence['messages'] || ['requested-test@example.test'] !== $evidence['recipients']) {
    throw new RuntimeException('Explicit test did not produce exactly one synthetic message.');
}
echo "Strict direct TLS, STARTTLS and authentication verified; connection-only and failure tests sent no DATA; explicit test sent exactly one synthetic message.\n";
