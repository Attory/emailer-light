<?php

declare(strict_types=1);

// Disposable source-level browser fixture, never a customer setup entry point.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Module\Configuration\Application\Contract\ConfigurationSetup;
use App\Module\Configuration\Application\Contract\MailInput;
use App\Module\Configuration\Application\Contract\OrganisationInput;
use App\Module\Configuration\Application\Contract\PolicyInput;
use App\Module\Identity\Application\Contract\AdministratorSetup;
use App\Module\Setup\Application\Contract\InstallationFinalizer;
use App\Module\Setup\Application\Contract\SetupState;
use App\Module\Setup\Application\DatabaseSetup;
use Doctrine\DBAL\Connection;

$phase = 'fixture guard';
try {
    if ('test' !== getenv('APP_ENV') || 'https://emailer.example.test' !== getenv('DEFAULT_URI')) {
        throw new RuntimeException('Not the disposable test profile.');
    }
    $kernel = new App\Kernel('test', false);
    $kernel->boot();
    $container = $kernel->getContainer()->get('test.service_container');
    $db = $container->get(Connection::class);
    if ('emailer_browser_test' !== $db->getDatabase() || 'database' !== ($db->getParams()['host'] ?? null)) {
        throw new RuntimeException('Not the disposable database.');
    }
    $config = json_decode(file_get_contents('/emailer-browser-secrets/browser-fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    $phase = 'real setup';
    // The test kernel deliberately uses an external test-only key path. The
    // browser fixture mounts it persistently across disposable PHP processes.
    $container->get(App\Module\Secrets\Application\Contract\MasterKey::class)->create();
    $state = $container->get(SetupState::class);
    if (!$state->progress()->completed) {
        $container->get(DatabaseSetup::class)->configureRuntime();
        $id = $state->initializeKey();
        $configuration = $container->get(ConfigurationSetup::class);
        $configuration->saveOrganisation($id, new OrganisationInput('Example newsletter', 'https://emailer.example.test', 'en_AU', 'UTC'));
        $state->mark('organisation');
        $container->get(AdministratorSetup::class)->create($config['email'], $config['password']);
        $state->mark('administrator');
        // This one clearly identified setup message goes only to the isolated sink.
        $configuration->testAndSaveMail($id, new MailInput('mailpit', 1025, 'none', '', '', 'editor-sender@example.test', 'Example newsletter', ''));
        $state->mark('mail');
        $configuration->savePolicies(new PolicyInput(60, 1, 5, 10000, 10485760, 100000, 30, 365, 'Example newsletter. Use the unsubscribe link below.', 'supervised'));
        $state->mark('policy');
        $state->mark('capability');
        $container->get(InstallationFinalizer::class)->finalize();
    }
    // Idempotently restore the lock after a disposable test cache rebuild.
    $container->get(InstallationFinalizer::class)->finalize();
    if (!file_exists('/emailer-browser-secrets/banner.png')) {
        $image = imagecreatetruecolor(1600, 900);
        imagefill($image, 0, 0, imagecolorallocate($image, 242, 222, 184));
        imagefilledrectangle($image, 100, 100, 1500, 800, imagecolorallocate($image, 176, 70, 42));
        imagestring($image, 5, 140, 140, 'EXAMPLE NEWSLETTER', imagecolorallocate($image, 255, 248, 233));
        imagepng($image, '/emailer-browser-secrets/banner.png');
        chmod('/emailer-browser-secrets/banner.png', 0o600);
        unset($image);
    }
    echo "Disposable editor installation ready.\n";
} catch (Throwable) {
    // Never dump exceptions, requests, DSNs or credentials from a browser fixture.
    fwrite(STDERR, 'Editor fixture failed at '.$phase.".\n");
    exit(1);
}
