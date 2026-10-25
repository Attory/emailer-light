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

final class TemplateEditorHttpTest extends WebTestCase
{
    public function testUploadFailureRetryValidationAndEditRetainFieldsWithoutPublishing(): void
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
            $login = $client->request('GET', '/login', server: $server);
            $client->request('POST', '/login', ['login_identifier' => 'editor@example.test', 'password' => $password,
                '_token' => self::token($login, 'form input[name="_token"]')], server: $server);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            $authenticatedRedirect = $client->getResponse()->headers->get('location');
            self::assertSame('https://emailer.example.test/admin/templates/assets', $authenticatedRedirect);

            $source = $private.'/incoming.png';
            $image = imagecreatetruecolor(32, 16);
            self::assertInstanceOf(\GdImage::class, $image);
            self::assertTrue(imagepng($image, $source));
            unset($image);
            $bad = $private.'/not-a-raster.svg';
            file_put_contents($bad, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
            $values = ['name' => 'Example <&> draft', 'subject' => 'A retained subject', 'preheader' => 'A retained preheader',
                'html_body' => "<p>Before the image.</p>\n<p>After the image.</p>", 'text_body' => "Retained plain text.\nSecond line."];
            $form = $client->request('GET', '/admin/templates', server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $assetToken = self::token($form, '[name="_asset_token"]');
            $upload = [...$values, '_asset_token' => $assetToken, '_editor_kind' => 'create', '_editor_id' => ''];

            $invalid = $client->request('POST', '/admin/templates/assets/upload', $upload,
                ['image' => new UploadedFile($bad, 'example.svg', 'image/svg+xml', test: true)], server: $server);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertValues($invalid, $values);
            self::assertStringContainsString('JPEG', $invalid->filter('[data-template-error]')->text());
            self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM template_asset')));

            $uploaded = $client->request('POST', '/admin/templates/assets/upload', $upload,
                ['image' => new UploadedFile($source, 'example.png', 'image/png', test: true)], server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertValues($uploaded, $values);
            self::assertSame('true', $uploaded->filter('[data-template-editor]')->attr('data-editor-unsaved'));
            self::assertSame(0, $uploaded->filter('img[alt="Uploaded template asset preview"]')->count());
            self::assertSame(1, $uploaded->filter('.template-image-thumbnail[width="160"][height="120"]')->count());
            $client->request('GET', '/admin/templates/assets', server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertStringContainsString('no-store', $client->getResponse()->headers->get('cache-control', ''));
            $gallery = self::responseArray($client->getResponse()->getContent());
            self::assertIsArray($gallery['items']);
            self::assertCount(1, $gallery['items']);
            $asset = $gallery['items'][0];
            self::assertIsArray($asset);
            self::assertIsString($asset['url']);
            $assetUrl = $asset['url'];
            self::assertMatchesRegularExpression('#\A/template-assets/[a-f0-9]{48}\.png\z#', $assetUrl);
            $client->request('GET', $assetUrl, server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame('image/png', $client->getResponse()->headers->get('content-type'));

            $client->request('POST', '/admin/templates/assets/upload', ['_token' => 'invalid'],
                ['image' => new UploadedFile($source, 'example.png', 'image/png', test: true)],
                server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(400, $client->getResponse()->getStatusCode());
            self::assertSame(1, self::number($database->fetchOne('SELECT COUNT(*) FROM template_asset')));
            $client->request('GET', '/admin/templates/assets', ['before' => ['not-a-string']], server: $server);
            self::assertSame(400, $client->getResponse()->getStatusCode());

            $form = $client->request('GET', '/admin/templates', server: $server);
            $invalidValues = [...$values, 'html_body' => '<p>{{ unknown_field }}</p>'];
            $rejected = $client->request('POST', '/admin/templates/create', [...$invalidValues,
                '_token' => self::token($form, '[data-template-editor] [name="_token"]')], server: $server);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertValues($rejected, $invalidValues);
            self::assertStringContainsString('{{ unknown_field }}', $rejected->filter('[data-template-error]')->text());
            self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM email_template')));

            $client->request('POST', '/admin/templates/create', [...$values,
                '_token' => self::token($rejected, '[data-template-editor] [name="_token"]')], server: $server);
            self::assertSame(303, $client->getResponse()->getStatusCode());
            $location = $client->getResponse()->headers->get('location');
            self::assertIsString($location);
            self::assertSame(1, preg_match('#\A/admin/templates/([a-f0-9-]{36})/versions\z#', $location, $match));
            if (!isset($match[1])) {
                self::fail('The successful save did not identify its template.');
            }
            $templateId = $match[1];
            self::assertSame('draft', $database->fetchOne('SELECT status FROM email_template WHERE id = ?', [$templateId]));
            self::assertNull($database->fetchOne('SELECT published_version_id FROM email_template WHERE id = ?', [$templateId]));

            $editUrl = '/admin/templates/'.$templateId.'/edit';
            $edit = $client->request('GET', $editUrl, server: $server);
            $edited = [...$values, 'name' => 'Edited unsent draft', 'subject' => 'Edited subject',
                'html_body' => '<script>window.editorInjection = true;</script>{% unsupported %}<p>Keep this unsaved HTML.</p>'];
            $editFailure = $client->request('POST', $editUrl, [...$edited,
                '_token' => self::token($edit, '[data-template-editor] [name="_token"]')], server: $server);
            self::assertSame(422, $client->getResponse()->getStatusCode());
            self::assertValues($editFailure, $edited);
            self::assertSame(0, $editFailure->filterXPath('//script[contains(., "window.editorInjection")]')->count());
            self::assertSame(1, self::number($database->fetchOne('SELECT COUNT(*) FROM template_version WHERE template_id = ?', [$templateId])));

            $afterEditUpload = $client->request('POST', '/admin/templates/assets/upload', [...$edited,
                '_asset_token' => self::token($editFailure, '[name="_asset_token"]'), '_editor_kind' => 'edit', '_editor_id' => $templateId],
                ['image' => new UploadedFile($source, 'second.png', 'image/png', test: true)], server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertValues($afterEditUpload, $edited);
            self::assertSame(2, $afterEditUpload->filter('.template-image-thumbnail')->count());
            self::assertSame($values['name'], $database->fetchOne('SELECT name FROM email_template WHERE id = ?', [$templateId]));

            $final = [...$edited, 'html_body' => '<p>Unpublished image draft.</p><img src="'.$assetUrl.'" alt="A descriptive image" width="32" height="16">'];
            $client->request('POST', $editUrl, [...$final,
                '_token' => self::token($afterEditUpload, '[data-template-editor] [name="_token"]')],
                server: [...$server, 'HTTP_ACCEPT' => 'application/json']);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            $saved = self::responseArray($client->getResponse()->getContent());
            self::assertTrue($saved['saved']);
            self::assertSame(2, self::number($database->fetchOne('SELECT COUNT(*) FROM template_version WHERE template_id = ?', [$templateId])));
            self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM template_version WHERE published_at IS NOT NULL')));
            self::assertNull($database->fetchOne('SELECT published_version_id FROM email_template WHERE id = ?', [$templateId]));
            foreach (['campaign', 'messenger_messages', 'delivery_attempt'] as $table) {
                self::assertSame(0, self::number($database->fetchOne('SELECT COUNT(*) FROM '.$table)));
            }
            $returned = $client->request('GET', '/admin/templates', server: $server);
            self::assertSame(200, $client->getResponse()->getStatusCode());
            self::assertSame(2, $returned->filter('.template-image-thumbnail')->count());
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

    /** @param array<string, string> $values */
    private static function assertValues(Crawler $crawler, array $values): void
    {
        foreach ($values as $name => $expected) {
            $node = $crawler->filter('[data-template-editor] [name="'.$name.'"]')->getNode(0);
            self::assertInstanceOf(\DOMElement::class, $node);
            self::assertSame($expected, 'textarea' === $node->tagName ? $node->textContent : $node->getAttribute('value'), $name.' was not retained.');
        }
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
