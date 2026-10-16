<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Health\Application\Contract\LightweightReadinessProbe;
use App\Module\Health\Application\Contract\SendingGateReadinessProbe;
use App\Module\Secrets\Application\Contract\MasterKey;
use Doctrine\DBAL\Connection;

final readonly class SystemReadinessProbe implements LightweightReadinessProbe, SendingGateReadinessProbe
{
    public function __construct(private Connection $connection, private MasterKey $masterKey, private string $privateDirectory, private string $projectDirectory, private string $canonicalUri, private string $environment, private string $trustedProxies, private string $trustedHosts, private string $expectedSchemaVersion, private string $releasesDirectory, private string $releasePublicKeyFile, private string $httpMode = 'proxy')
    {
    }

    public function findings(): array
    {
        $findings = [];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            if (!$this->connection->createSchemaManager()->tablesExist(['health_check_run', 'health_finding_state', 'backup_record', 'backup_transfer', 'release_operation', 'housekeeping_run', 'operations_policy', 'operations_run'])) {
                $findings[] = $this->critical('schema_incompatible', 'The Phase 8 database schema is incomplete.', 'Run reviewed migrations before enabling sending.');
            }
            $schema = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY executed_at DESC, version DESC LIMIT 1');
            if (!\is_string($schema) || $this->expectedSchemaVersion !== substr($schema, -14)) {
                $findings[] = $this->critical('schema_version_mismatch', 'Application code and database schema versions differ.', 'Apply the exact reviewed migrations for this application release.');
            }
            $lastUpdate = $this->connection->fetchAssociative("SELECT status, detail_code FROM release_operation WHERE operation = 'update' ORDER BY started_at DESC, id DESC LIMIT 1");
            if (false !== $lastUpdate && 'failed' === $lastUpdate['status'] && 'rollback_failed' === $lastUpdate['detail_code']) {
                $findings[] = $this->critical('release_schema_recovery', 'A failed update requires supervised schema recovery.', 'Keep delivery quiesced; recover the exact reviewed database schema and release before resuming.');
            }
            $server = $this->connection->fetchOne('SELECT VERSION()');
            if (!\is_string($server) || 1 !== preg_match('/^(\d+\.\d+)/', $server, $matches) || !\in_array($matches[1], ['10.11', '11.8'], true)) {
                $findings[] = $this->critical('database_version', 'The database server version is unsupported.', 'Use a project-tested MariaDB 10.11 or 11.8 release.');
            }
        } catch (\Throwable) {
            $findings[] = $this->critical('database_unavailable', 'The application database is unavailable.', 'Restore database connectivity and rerun Doctor.');
        }
        try {
            $key = $this->masterKey->load();
            sodium_memzero($key);
        } catch (\Throwable) {
            $findings[] = $this->critical('master_key_unavailable', 'The external master key is unavailable or unsafe.', 'Restore the configured key or recovery package; never replace it blindly.');
        }
        if (!str_starts_with($this->canonicalUri, 'https://') && !\in_array($this->environment, ['dev', 'test'], true)) {
            $findings[] = $this->critical('canonical_https', 'The canonical application URI is not HTTPS.', 'Configure the canonical HTTPS URI and trusted proxy boundary.');
        }
        if (!\in_array($this->environment, ['dev', 'test'], true) && (('direct-fpm' !== $this->httpMode && '' === trim($this->trustedProxies)) || !\in_array($this->httpMode, ['proxy', 'direct-fpm'], true) || ('direct-fpm' === $this->httpMode && '' !== trim($this->trustedProxies)) || str_contains($this->trustedProxies, '*') || str_contains($this->trustedProxies, '0.0.0.0/0') || str_contains($this->trustedProxies, '::/0'))) {
            $findings[] = $this->critical('trusted_proxies', 'The HTTP deployment boundary is missing or unsafe.', 'Use exact TLS proxy hops, or explicit direct FastCGI mode without trusted proxies.');
        }
        if ('' === trim($this->trustedHosts) || str_contains($this->trustedHosts, '.*') || str_contains($this->trustedHosts, '*')) {
            $findings[] = $this->critical('trusted_hosts', 'Trusted host configuration is empty or over-broad.', 'Configure a strict canonical-host regular expression.');
        } else {
            $canonicalHost = parse_url($this->canonicalUri, PHP_URL_HOST);
            if (!\is_string($canonicalHost) || 1 !== @preg_match('~'.$this->trustedHosts.'~D', $canonicalHost)) {
                $findings[] = $this->critical('trusted_host_mismatch', 'The canonical host is not permitted by the trusted-host boundary.', 'Add only the exact canonical host to the anchored trusted-host expression.');
            }
        }
        if (!is_dir($this->privateDirectory) || !is_writable($this->privateDirectory) || $this->insidePublic($this->privateDirectory) || (!\in_array($this->environment, ['dev', 'test'], true) && $this->insideProject($this->privateDirectory))) {
            $findings[] = $this->critical('private_storage', 'Private writable storage is unavailable or public.', 'Provide a writable private directory outside the public document root.');
        } else {
            $free = @disk_free_space($this->privateDirectory);
            if (false === $free || $free < 536870912) {
                $findings[] = $this->critical('disk_space', 'Private storage has less than 512 MiB available.', 'Free disk space before sending, backup, or update operations.');
            } elseif ($free < 2147483648) {
                $findings[] = ['code' => 'disk_space_low', 'severity' => 'warning', 'summary' => 'Private storage has less than 2 GiB available.', 'remediation' => 'Increase or clean private storage soon.'];
            }
        }
        if (!$this->safePublicTree()) {
            $findings[] = $this->critical('public_boundary', 'The public tree contains an unsafe link or PHP entry point.', 'Deploy only the verified artifact with document root exactly public/.');
        }
        if (!\in_array($this->environment, ['dev', 'test'], true) && (!$this->safeExternalDirectory($this->releasesDirectory) || !$this->safePublicKey())) {
            $findings[] = $this->critical('release_trust_storage', 'Immutable release storage or its pinned public key is unavailable or unsafe.', 'Provision an external releases directory and non-writable pinned Ed25519 public key.');
        }

        return $findings;
    }

    /** @return array{code: string, severity: 'critical', summary: string, remediation: string} */
    private function critical(string $code, string $summary, string $remediation): array
    {
        return ['code' => $code, 'severity' => 'critical', 'summary' => $summary, 'remediation' => $remediation];
    }

    private function insidePublic(string $path): bool
    {
        $resolved = realpath($path);
        $public = realpath($this->projectDirectory.'/public');

        return false !== $resolved && false !== $public && ($resolved === $public || str_starts_with($resolved.'/', rtrim($public, '/').'/'));
    }

    private function insideProject(string $path): bool
    {
        $resolved = realpath($path);
        $project = realpath($this->projectDirectory);

        return false !== $resolved && false !== $project && ($resolved === $project || str_starts_with($resolved.'/', rtrim($project, '/').'/'));
    }

    private function safeExternalDirectory(string $path): bool
    {
        $stat = @lstat($path);

        return '' !== $path && str_starts_with($path, '/') && \is_array($stat) && 0o040000 === ($stat['mode'] & 0o170000) && 0 === ($stat['mode'] & 0o002) && !is_link($path) && is_readable($path) && !$this->insideProject($path);
    }

    private function safePublicKey(): bool
    {
        $stat = @lstat($this->releasePublicKeyFile);

        return '' !== $this->releasePublicKeyFile && str_starts_with($this->releasePublicKeyFile, '/') && \is_array($stat) && 0o100000 === ($stat['mode'] & 0o170000) && 1 === $stat['nlink'] && 0 === ($stat['mode'] & 0o022) && !$this->insideProject($this->releasePublicKeyFile);
    }

    private function safePublicTree(): bool
    {
        $public = $this->projectDirectory.'/public';
        if (!is_dir($public) || is_link($public)) {
            return false;
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($public, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                return false;
            }
            if ($entry->isFile() && 'php' === strtolower($entry->getExtension()) && $entry->getPathname() !== $public.'/index.php') {
                return false;
            }
        }

        return !file_exists($public.'/.git') && !file_exists($public.'/.env');
    }
}
