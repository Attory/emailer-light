<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Setup\Application\Contract\InstallationLock;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LoginSessionTest extends WebTestCase
{
    public function testPasswordLoginCsrfLogoutAndSessionExpiry(): void
    {
        $private = sys_get_temp_dir().'/light-http-'.bin2hex(random_bytes(8));
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
        try {
            $id = '01990101-0000-7000-8000-000000000101';
            $now = '2026-01-01 00:00:00.000000';
            $database->insert('app_user', ['id' => $id, 'singleton_key' => '1', 'login_identifier' => 'login-admin@example.test', 'password_hash' => password_hash('synthetic-login-phrase-2026', PASSWORD_ARGON2ID), 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
            $database->insert('organisation_settings', ['singleton_key' => '1', 'installation_uuid' => $id, 'display_name' => 'Synthetic', 'canonical_base_url' => 'https://emailer.example.test', 'locale' => 'en_AU', 'time_zone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
            $server = ['HTTPS' => 'on', 'HTTP_HOST' => 'emailer.example.test'];

            $client->request('GET', '/admin', server: $server);
            self::assertResponseRedirects('/login');
            $crawler = $client->request('GET', '/login', server: $server);
            self::assertResponseIsSuccessful();
            $csrf = $crawler->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrf);
            $credentials = ['login_identifier' => 'login-admin@example.test', 'password' => 'synthetic-login-phrase-2026'];

            $client->request('POST', '/login', [...$credentials, '_token' => $csrf], server: [...$server, 'REMOTE_ADDR' => '198.51.100.42']);
            self::assertResponseRedirects('/admin');
            $dashboard = $client->request('GET', '/admin', server: $server);
            self::assertResponseIsSuccessful();

            $client->request('POST', '/admin/logout', ['_token' => 'invalid'], server: $server);
            self::assertResponseStatusCodeSame(400);
            $logoutCsrf = $dashboard->filter('form[action="/admin/logout"] input[name="_token"]')->attr('value');
            self::assertIsString($logoutCsrf);
            $client->request('POST', '/admin/logout', ['_token' => $logoutCsrf], server: $server);
            self::assertResponseRedirects('/login');

            $crawler = $client->request('GET', '/login', server: $server);
            $csrf = $crawler->filter('input[name="_token"]')->attr('value');
            self::assertIsString($csrf);
            $client->request('POST', '/login', [...$credentials, '_token' => $csrf], server: [...$server, 'REMOTE_ADDR' => '198.51.100.43']);
            self::assertResponseRedirects('/admin');
            $database->executeStatement("UPDATE user_session SET idle_expires_at = '2020-01-01 00:00:00.000000' WHERE user_id = ? AND revoked_at IS NULL", [$id]);
            $client->request('GET', '/admin', server: $server);
            self::assertResponseRedirects('/login');
            $client->request('POST', '/login', [...$credentials, '_token' => 'invalid'], server: [...$server, 'REMOTE_ADDR' => '192.0.2.9']);
            self::assertResponseRedirects('/login');
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
            if (null === $previousPrivate) {
                unset($_SERVER['EMAILER_PRIVATE_DIR']);
            } else {
                $_SERVER['EMAILER_PRIVATE_DIR'] = $previousPrivate;
            }
            unlink($private.'/install.lock');
            $sessions = glob($private.'/sessions/*');
            foreach (\is_array($sessions) ? $sessions : [] as $session) {
                unlink($session);
            }
            if (is_dir($private.'/sessions')) {
                rmdir($private.'/sessions');
            }
            rmdir($private);
        }
    }
}
