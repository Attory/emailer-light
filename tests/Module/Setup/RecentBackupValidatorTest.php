<?php

declare(strict_types=1);

namespace App\Tests\Module\Setup;

use App\Module\Setup\Infrastructure\RecentBackupValidator;
use PHPUnit\Framework\TestCase;

final class RecentBackupValidatorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private string $directory;
    private string $publicDirectory;
    private RecentBackupValidator $validator;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-backup-evidence-'.bin2hex(random_bytes(8));
        $this->publicDirectory = $this->directory.'/public';
        self::assertTrue(mkdir($this->publicDirectory, 0o755, true));
        $this->validator = new RecentBackupValidator($this->publicDirectory);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->directory);
    }

    public function testEmptyFileIsRejected(): void
    {
        $path = $this->writeEvidence('', $this->directory.'/empty.backup');

        self::assertFalse($this->validator->isValid($path, self::NOW));
    }

    public function testSymlinkAndHardLinkAreRejected(): void
    {
        $target = $this->writeEvidence($this->matchingEvidence(), $this->directory.'/target.backup');
        $symlink = $this->directory.'/symlink.backup';
        $hardLink = $this->directory.'/hard-link.backup';
        self::assertTrue(symlink($target, $symlink));
        self::assertTrue(link($target, $hardLink));

        self::assertFalse($this->validator->isValid($symlink, self::NOW));
        self::assertFalse($this->validator->isValid($hardLink, self::NOW));
    }

    public function testStaleUnsafeAndPublicPathFilesAreRejected(): void
    {
        $stale = $this->writeEvidence($this->matchingEvidence(), $this->directory.'/stale.backup');
        self::assertTrue(touch($stale, self::NOW - 86401));
        $unsafe = $this->writeEvidence($this->matchingEvidence(), $this->directory.'/unsafe.backup', 0o620);
        $public = $this->writeEvidence($this->matchingEvidence(), $this->publicDirectory.'/public.backup');

        self::assertFalse($this->validator->isValid($stale, self::NOW));
        self::assertFalse($this->validator->isValid($unsafe, self::NOW));
        self::assertFalse($this->validator->isValid($public, self::NOW));
    }

    public function testMalformedAndIntegrityMismatchedEvidenceAreRejected(): void
    {
        $malformed = $this->writeEvidence('{not-json', $this->directory.'/malformed.backup');
        $mismatched = $this->writeEvidence(json_encode([
            'format' => 'emailer-light-backup-evidence-v1',
            'created_at' => self::NOW - 60,
            'payload' => base64_encode('synthetic payload'),
            'sha256' => str_repeat('0', 64),
        ], JSON_THROW_ON_ERROR), $this->directory.'/mismatched.backup');

        self::assertFalse($this->validator->isValid($malformed, self::NOW));
        self::assertFalse($this->validator->isValid($mismatched, self::NOW));
    }

    public function testMatchingSelfDeclaredDigestStillFailsClosedUntilBackupToolingExists(): void
    {
        $path = $this->writeEvidence($this->matchingEvidence(), $this->directory.'/matching.backup');

        self::assertFalse($this->validator->isValid($path, self::NOW));
    }

    private function matchingEvidence(): string
    {
        $payload = 'synthetic payload';

        return json_encode([
            'format' => 'emailer-light-backup-evidence-v1',
            'created_at' => self::NOW - 60,
            'payload' => base64_encode($payload),
            'sha256' => hash('sha256', $payload),
        ], JSON_THROW_ON_ERROR);
    }

    private function writeEvidence(string $contents, string $path, int $mode = 0o600): string
    {
        self::assertNotFalse(file_put_contents($path, $contents));
        self::assertTrue(chmod($path, $mode));
        self::assertTrue(touch($path, self::NOW - 60));

        return $path;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }

        $entries = scandir($directory);

        if (false === $entries) {
            return;
        }

        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            self::removeDirectory($directory.'/'.$entry);
        }

        @rmdir($directory);
    }
}
