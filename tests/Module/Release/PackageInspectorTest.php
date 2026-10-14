<?php

declare(strict_types=1);

namespace App\Tests\Module\Release;

use App\Module\Release\Application\PackageInspector;
use PHPUnit\Framework\TestCase;

final class PackageInspectorTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/emailer-release-test-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0o700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory.'/*');
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testValidDetachedSignatureAndEveryFileDigestAreRequired(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        $manifest = (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
        self::assertSame('0.1.0', $manifest['version']);

        file_put_contents($archive.'.sig', sodium_bin2base64(random_bytes(SODIUM_CRYPTO_SIGN_BYTES), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testTamperedArchiveAndIncompatibleRuntimeAreRejected(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0', '99.0.0');
        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testWrongPinnedKeyIsRejectedBeforeExtraction(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        $wrongPair = sodium_crypto_sign_keypair();
        file_put_contents($publicKey, sodium_bin2base64(sodium_crypto_sign_publickey($wrongPair), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testArchiveTamperIsRejectedBeforeFileUse(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0');
        file_put_contents($archive, 'tamper', FILE_APPEND);

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testEmbeddedRuntimeVersionMustMatchSignedManifest(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0', runtimeVersion: '0.2.0');

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.1.0-dev'))->inspect($archive);
    }

    public function testVersionOneAcceptsEveryDeclaredReleaseLineAndRejectsOlderSources(): void
    {
        [$archive, $publicKey] = $this->fixture('1.0.0', minimumSource: '0.1.0', supportedSources: ['0.1.0', '0.2.0', '0.3.0', '0.4.0']);

        foreach (['0.1.0', '0.2.0', '0.3.0', '0.4.0'] as $source) {
            $manifest = (new PackageInspector($publicKey, $source))->inspect($archive);
            self::assertSame('1.0.0', $manifest['version']);
        }

        self::assertSame('1.0.0', (new PackageInspector($publicKey, '1.0.0'))->inspect($archive)['version']);

        foreach (['0.0.9', '0.1.9', '0.3.7', '0.9.0'] as $unsupported) {
            try {
                (new PackageInspector($publicKey, $unsupported))->inspect($archive);
                self::fail('Unsupported source version was accepted: '.$unsupported);
            } catch (\RuntimeException) {
            }
        }
    }

    public function testSbomWithoutAnAssertedProductionLicenseIsRejected(): void
    {
        [$archive, $publicKey] = $this->fixture('1.0.0', minimumSource: '0.1.0', license: 'NOASSERTION');

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.4.0'))->inspect($archive);
    }

    public function testCurrentManifestCannotUseLegacySourceEvidenceToBypassLicenseDecision(): void
    {
        [$archive, $publicKey] = $this->fixture('0.4.0', minimumSource: '0.3.0', license: 'NOASSERTION');

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.4.0'))->inspectInstalledSource($archive);
    }

    public function testLegacySignedPackageIsAcceptedOnlyAsExactInstalledSourceEvidence(): void
    {
        [$archive, $publicKey] = $this->fixture('0.1.0', minimumSource: '0.1.0-rc.1', license: 'NOASSERTION', legacyManifest: true);
        $inspector = new PackageInspector($publicKey, '0.1.0');

        self::assertSame('0.1.0', $inspector->inspectInstalledSource($archive)['version']);

        try {
            $inspector->inspect($archive);
            self::fail('A legacy package was accepted as a target package.');
        } catch (\RuntimeException) {
        }

        $this->expectException(\RuntimeException::class);
        (new PackageInspector($publicKey, '0.2.0'))->inspectInstalledSource($archive);
    }

    /**
     * @param list<string>|null $supportedSources
     *
     * @return array{string, string}
     */
    private function fixture(string $version, string $phpMinimum = '8.4.0', ?string $runtimeVersion = null, string $minimumSource = '0.1.0-dev', string $license = 'MIT', ?array $supportedSources = null, bool $legacyManifest = false): array
    {
        $archive = $this->directory.'/emailer-light-'.$version.'.zip';
        $payload = '<?php return true;';
        $console = "#!/usr/bin/env php\n<?php return true;";
        $lock = '{"packages":[]}';
        $services = "parameters:\n    emailer.app_version: '".($runtimeVersion ?? $version)."'\n";
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('public/index.php', $payload));
        self::assertTrue($zip->addFromString('bin/console', $console));
        self::assertTrue($zip->addFromString('composer.lock', $lock));
        self::assertTrue($zip->addFromString('config/services.yaml', $services));
        $zip->close();
        $manifest = ['format' => 'emailer-light-release-v1', 'version' => $version, 'source_commit' => str_repeat('a', 40), 'minimum_source_version' => $minimumSource, 'minimum_schema_version' => '20260824000000', 'schema_version' => '20260824000000', 'rollback_schema_compatible' => true, 'platform' => ['php_min' => $phpMinimum, 'php_max_exclusive' => '100.0.0', 'mariadb' => ['10.11', '11.8']], 'files' => ['bin/console' => hash('sha256', $console), 'composer.lock' => hash('sha256', $lock), 'config/services.yaml' => hash('sha256', $services), 'public/index.php' => hash('sha256', $payload)]];
        if (!$legacyManifest) {
            $manifest['supported_source_versions'] = $supportedSources ?? [$minimumSource];
        }
        $manifestBytes = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $checksumBytes = hash_file('sha256', $archive).'  '.basename($archive)."\n";
        $sbomBytes = json_encode(['spdxVersion' => 'SPDX-2.3', 'dataLicense' => 'CC0-1.0', 'SPDXID' => 'SPDXRef-DOCUMENT', 'name' => 'Emailer Light '.$version, 'documentNamespace' => 'https://attory.example.test/spdx/emailer-light/'.str_repeat('a', 40).'/'.$version, 'packages' => [['SPDXID' => 'SPDXRef-Package-synthetic', 'name' => 'synthetic/runtime', 'versionInfo' => '1.0.0', 'licenseDeclared' => $license]]], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        file_put_contents($archive.'.manifest.json', $manifestBytes);
        file_put_contents($archive.'.sha256', $checksumBytes);
        file_put_contents($archive.'.spdx.json', $sbomBytes);
        $keyPair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keyPair);
        $public = sodium_crypto_sign_publickey($keyPair);
        $signature = sodium_crypto_sign_detached($manifestBytes.$checksumBytes.hash('sha256', $sbomBytes), $secret);
        file_put_contents($archive.'.sig', sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        $publicKey = $this->directory.'/release.pub';
        file_put_contents($publicKey, sodium_bin2base64($public, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)."\n");
        sodium_memzero($secret);

        return [$archive, $publicKey];
    }
}
