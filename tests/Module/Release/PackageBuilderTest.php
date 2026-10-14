<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use PHPUnit\Framework\TestCase;

final class PackageBuilderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-package-builder-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/artifact/bin', 0o700, true);
        mkdir($this->directory.'/artifact/config', 0o700, true);
        mkdir($this->directory.'/artifact/public', 0o700, true);
        mkdir($this->directory.'/first', 0o700);
        mkdir($this->directory.'/second', 0o700);
        file_put_contents($this->directory.'/artifact/bin/console', "#!/usr/bin/env php\n<?php return true;\n");
        file_put_contents($this->directory.'/artifact/public/index.php', "<?php return true;\n");
        file_put_contents($this->directory.'/artifact/config/services.yaml', "parameters:\n    emailer.app_version: '0.1.0'\n");
        file_put_contents($this->directory.'/artifact/composer.lock', json_encode(['packages' => [['name' => 'synthetic/runtime', 'version' => '1.2.3', 'license' => ['MIT']]]], JSON_THROW_ON_ERROR));
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        file_put_contents($this->directory.'/signing.key', sodium_bin2base64($secret, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        chmod($this->directory.'/signing.key', 0o600);
        sodium_memzero($secret);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->directory);
    }

    public function testBuildIsByteReproducibleAndDeclaresLockedLicense(): void
    {
        $first = $this->build($this->directory.'/first');
        $second = $this->build($this->directory.'/second');
        foreach (['', '.manifest.json', '.sha256', '.spdx.json', '.sig'] as $suffix) {
            self::assertFileEquals($first.$suffix, $second.$suffix);
        }
        $sbom = json_decode((string) file_get_contents($first.'.spdx.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($sbom);
        $packages = $sbom['packages'] ?? null;
        self::assertIsArray($packages);
        $package = $packages[0] ?? null;
        self::assertIsArray($package);
        self::assertSame('MIT', $package['licenseDeclared'] ?? null);
        $manifest = json_decode((string) file_get_contents($first.'.manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame('0.1.0', $manifest['version'] ?? null);
        self::assertSame(['0.1.0'], $manifest['supported_source_versions'] ?? null);
        self::assertSame('20260824000000', $manifest['minimum_schema_version'] ?? null);
        self::assertSame('20260824010000', $manifest['schema_version'] ?? null);
    }

    public function testMalformedMinimumSourceAndLinkedSigningKeyFailClosed(): void
    {
        self::assertSame(2, $this->runBuilder($this->directory.'/first', 'not-semver'));
        self::assertSame(2, $this->runBuilder($this->directory.'/first', '0.1.0-01'));
        symlink($this->directory.'/signing.key', $this->directory.'/linked.key');
        self::assertSame(2, $this->runBuilder($this->directory.'/second', '0.1.0', $this->directory.'/linked.key'));
    }

    public function testManifestVersionMustMatchEmbeddedRuntimeVersion(): void
    {
        file_put_contents($this->directory.'/artifact/config/services.yaml', "parameters:\n    emailer.app_version: '0.2.0'\n");
        self::assertSame(2, $this->runBuilder($this->directory.'/first'));
    }

    private function build(string $output): string
    {
        self::assertSame(0, $this->runBuilder($output));

        return $output.'/emailer-light-0.1.0.zip';
    }

    private function runBuilder(string $output, string $minimumSource = '0.1.0', ?string $key = null): int
    {
        $command = [PHP_BINARY, \dirname(__DIR__, 3).'/tools/release/build-package.php', '--artifact='.$this->directory.'/artifact', '--output='.$output, '--version=0.1.0', '--source='.str_repeat('a', 40), '--minimum-source='.$minimumSource, '--minimum-schema=20260824000000', '--schema=20260824010000', '--rollback-schema-compatible=true', '--signing-key='.($key ?? $this->directory.'/signing.key'), '--source-date-epoch=1787466661'];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);

        return proc_close($process);
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory) || is_link($directory)) {
            @unlink($directory);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry instanceof \SplFileInfo) {
                $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            }
        }
        @rmdir($directory);
    }
}
