<?php

declare(strict_types=1);

namespace App\Tests\Integration\Light;

use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\AuthenticatedUser;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Templates\Application\TemplateAssetManager;
use App\Module\Templates\Infrastructure\TemplateAssetStore;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

final class TemplateAssetGalleryTest extends KernelTestCase
{
    public function testExistingUploadsRemainDiscoverableAcrossPagesAndMissingFilesAreNotSelectable(): void
    {
        self::bootKernel();
        $database = self::getContainer()->get(Connection::class);
        $authorization = self::getContainer()->get(AdministrativeAuthorization::class);
        $audit = self::getContainer()->get(AuditSink::class);
        self::assertInstanceOf(Connection::class, $database);
        self::assertInstanceOf(AdministrativeAuthorization::class, $authorization);
        self::assertInstanceOf(AuditSink::class, $audit);
        $directory = sys_get_temp_dir().'/light-gallery-'.bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $source = $directory.'/incoming.png';
        $image = imagecreatetruecolor(16, 8);
        self::assertInstanceOf(\GdImage::class, $image);
        self::assertTrue(imagepng($image, $source));
        unset($image);
        $database->beginTransaction();
        try {
            $id = Identity::uuidV7();
            $now = Identity::now();
            $hash = password_hash('synthetic-gallery-phrase-2026', PASSWORD_ARGON2ID);
            $database->insert('app_user', ['id' => $id, 'singleton_key' => '1', 'login_identifier' => 'gallery@example.test',
                'password_hash' => $hash, 'active' => 1, 'created_at' => $now, 'updated_at' => $now, 'password_changed_at' => $now]);
            $actor = new AuthenticatedUser($id, 'gallery@example.test', $hash, AdministrativeRole::Administrator, true);
            $storage = new TemplateAssetStore($directory.'/assets');
            $manager = new TemplateAssetManager($database, $authorization, $audit, $storage);
            $before = $this->businessCounts($database);
            $uploaded = [];
            for ($number = 0; $number < 26; ++$number) {
                $asset = $manager->store($actor, $source);
                $uploaded[$asset['id']] = $asset;
            }
            // A new manager instance represents returning to the editor later.
            $returning = new TemplateAssetManager($database, $authorization, $audit, $storage);
            $seen = [];
            $cursor = null;
            $pages = 0;
            do {
                $page = $returning->page($actor, $cursor);
                self::assertLessThanOrEqual(24, \count($page['items']));
                foreach ($page['items'] as $asset) {
                    self::assertArrayNotHasKey($asset['id'], $seen);
                    self::assertArrayHasKey($asset['id'], $uploaded);
                    self::assertSame($uploaded[$asset['id']]['url'], $asset['url']);
                    self::assertMatchesRegularExpression('#\A/template-assets/[a-f0-9]{48}\.png\z#', $asset['url']);
                    self::assertSame(16, $asset['width']);
                    self::assertSame(8, $asset['height']);
                    self::assertTrue($asset['available']);
                    $seen[$asset['id']] = true;
                }
                $cursor = $page['next_cursor'];
                self::assertLessThanOrEqual(2, ++$pages);
            } while (null !== $cursor);
            self::assertCount(26, $seen);
            self::assertSame($before, $this->businessCounts($database));

            $missing = $page['items'][0];
            unlink($storage->publicPath(basename($missing['url'])));
            $missingSeen = false;
            foreach ($returning->page($actor, $returning->page($actor)['next_cursor'])['items'] as $asset) {
                if ($asset['id'] === $missing['id']) {
                    self::assertFalse($asset['available']);
                    $missingSeen = true;
                }
            }
            self::assertTrue($missingSeen);
            self::assertSame(26, self::number($database->fetchOne('SELECT COUNT(*) FROM template_asset')));

            try {
                $returning->page($actor, '../not-an-asset');
                self::fail('Malformed gallery cursor was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertSame($before, $this->businessCounts($database));
            }
            $inactive = new AuthenticatedUser($id, 'gallery@example.test', $hash, AdministrativeRole::Administrator, false);
            try {
                $returning->page($inactive);
                self::fail('Inactive administrator could list image assets.');
            } catch (AccessDeniedException) {
                self::assertSame(26, self::number($database->fetchOne('SELECT COUNT(*) FROM template_asset')));
            }
        } finally {
            $database->rollBack();
            self::ensureKernelShutdown();
            $files = glob($directory.'/assets/*');
            foreach (\is_array($files) ? $files : [] as $file) {
                unlink($file);
            }
            if (is_dir($directory.'/assets')) {
                rmdir($directory.'/assets');
            }
            unlink($source);
            rmdir($directory);
        }
    }

    /** @return array<string, int> */
    private function businessCounts(Connection $database): array
    {
        $counts = [];
        foreach (['email_template', 'template_version', 'campaign', 'messenger_messages', 'delivery_attempt'] as $table) {
            $counts[$table] = self::number($database->fetchOne('SELECT COUNT(*) FROM '.$table));
        }

        return $counts;
    }

    private static function number(mixed $value): int
    {
        self::assertIsNumeric($value);

        return (int) $value;
    }
}
