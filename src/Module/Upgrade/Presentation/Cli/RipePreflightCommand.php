<?php

declare(strict_types=1);

namespace App\Module\Upgrade\Presentation\Cli;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Strictly read-only. An operator must stop services before running this command. */
#[AsCommand(name: 'emailer:upgrade:ripe-preflight', description: 'Read-only Light to Ripe edition-upgrade preflight; never sends mail or changes state.')]
final class RipePreflightCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        private readonly string $projectDirectory,
        private readonly string $privateDirectory,
        private readonly string $masterKeyFile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print redacted machine-readable results.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $findings = [];
        $report = ['format' => 'emailer-light-ripe-preflight-v1', 'product' => 'emailer-light', 'product_version' => '1.0.0', 'schema_version' => '20261014000000', 'upgrade_contract_version' => '1.0', 'status' => 'unsafe', 'findings' => []];
        try {
            $this->checkDatabase($findings);
            $this->checkFilesystem($findings);
        } catch (\Throwable) {
            $findings[] = 'preflight_check_failed';
        }
        $report['findings'] = array_values(array_unique($findings));
        $report['status'] = [] === $findings ? 'ready_for_future_ripe_installer' : 'unsafe';
        if (true === $input->getOption('json')) {
            $output->writeln(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $output->writeln('Emailer Light 1.0.0, schema 20261014000000, upgrade contract 1.0');
            $output->writeln([] === $findings ? 'Preflight passed. A compatible Ripe installer is still required.' : 'Preflight failed: '.implode(', ', $report['findings']));
        }

        return [] === $findings ? Command::SUCCESS : 3;
    }

    /** @param list<string> $findings */
    private function checkDatabase(array &$findings): void
    {
        $version = $this->connection->fetchOne('SELECT VERSION()');
        if (!\is_string($version) || 1 !== preg_match('/^(10\.(11|1[2-9]|[2-9][0-9])|1[1-9]\.)/', $version) || !str_contains(strtolower($version), 'mariadb')) {
            $findings[] = 'unsupported_database_engine';

            return;
        }
        $identity = $this->connection->fetchAssociative("SELECT product_id, product_version, schema_version, upgrade_contract_version, installation_uuid FROM product_identity WHERE singleton_key = '1'");
        if (false === $identity || 'emailer-light' !== $identity['product_id'] || '1.0.0' !== $identity['product_version'] || '20261014000000' !== $identity['schema_version'] || '1.0' !== $identity['upgrade_contract_version']) {
            $findings[] = 'unsupported_or_ambiguous_identity';

            return;
        }
        $installation = $this->connection->fetchAssociative("SELECT installation_uuid, completed_at FROM installation_state WHERE singleton_key = '1'");
        if (false === $installation || !\is_string($identity['installation_uuid']) || '' === $identity['installation_uuid'] || $installation['installation_uuid'] !== $identity['installation_uuid'] || null === $installation['completed_at']) {
            $findings[] = 'installation_incomplete';
        }
        $migration = $this->connection->fetchOne('SELECT version FROM doctrine_migration_versions ORDER BY version DESC LIMIT 1');
        if ('DoctrineMigrations\\Version20261014000000' !== $migration || 1 !== $this->count('SELECT COUNT(*) FROM doctrine_migration_versions')) {
            $findings[] = 'schema_version_mismatch';
        }
        $this->checkSchema($findings);
        $administrators = $this->connection->fetchAllAssociative('SELECT id, singleton_key, active, password_hash FROM app_user');
        if (1 !== \count($administrators) || '1' !== $administrators[0]['singleton_key'] || !is_numeric($administrators[0]['active']) || 1 !== (int) $administrators[0]['active'] || !\is_string($administrators[0]['password_hash']) || !str_starts_with($administrators[0]['password_hash'], '$argon2id$')) {
            $findings[] = 'administrator_invariant_failed';
        }
        $runtime = $this->connection->fetchAssociative("SELECT accepting_work, active_claims FROM delivery_runtime WHERE singleton_key = '1'");
        if (false === $runtime || !is_numeric($runtime['accepting_work']) || 0 !== (int) $runtime['accepting_work'] || !is_numeric($runtime['active_claims']) || 0 !== (int) $runtime['active_claims']) {
            $findings[] = 'delivery_not_quiesced';
        }
        if ($this->count("SELECT COUNT(*) FROM campaign WHERE state IN ('sending','queueing')") > 0 || $this->count("SELECT COUNT(*) FROM campaign_recipient WHERE state = 'processing'") > 0 || $this->count("SELECT COUNT(*) FROM delivery_attempt WHERE state = 'started'") > 0 || $this->count('SELECT COUNT(*) FROM messenger_messages') > 0) {
            $findings[] = 'active_or_queued_work';
        }
        if ($this->count("SELECT COUNT(*) FROM release_operation WHERE status = 'started'") > 0) {
            $findings[] = 'maintenance_operation_in_progress';
        }
        if ($this->count('SELECT COUNT(*) FROM worker_heartbeat WHERE stopped_at IS NULL AND heartbeat_at > UTC_TIMESTAMP(6) - INTERVAL 5 MINUTE') > 0) {
            $findings[] = 'active_worker_or_scheduler';
        }
        if ($this->count("SELECT COUNT(*) FROM campaign c LEFT JOIN campaign_audience_snapshot s ON s.campaign_id = c.id WHERE c.state NOT IN ('draft','review') AND s.campaign_id IS NULL") > 0
            || $this->count("SELECT COUNT(*) FROM campaign c INNER JOIN campaign_audience_snapshot s ON s.campaign_id = c.id WHERE c.state IN ('draft','review')") > 0
            || $this->count('SELECT COUNT(*) FROM campaign_recipient r LEFT JOIN campaign_audience_recipient a ON a.campaign_id = r.campaign_id AND a.recipient_id = r.recipient_id WHERE a.recipient_id IS NULL') > 0) {
            $findings[] = 'campaign_delivery_inconsistent';
        }
        foreach ($this->connection->fetchAllAssociative('SELECT campaign_id, recipient_count, audience_sha256 FROM campaign_audience_snapshot') as $snapshot) {
            $rows = $this->connection->fetchAllAssociative('SELECT recipient_id, email_canonical, profile_json FROM campaign_audience_recipient WHERE campaign_id = ? ORDER BY email_canonical', [$snapshot['campaign_id']]);
            $parts = [];
            foreach ($rows as $row) {
                if (!\is_string($row['recipient_id']) || !\is_string($row['email_canonical']) || !\is_string($row['profile_json'])) {
                    $findings[] = 'audience_snapshot_corrupt';
                    break;
                }
                $parts[] = $row['recipient_id']."\0".$row['email_canonical']."\0".$row['profile_json'];
            }
            if (!is_numeric($snapshot['recipient_count']) || \count($rows) !== (int) $snapshot['recipient_count'] || !\is_string($snapshot['audience_sha256']) || !hash_equals($snapshot['audience_sha256'], hash('sha256', implode("\n", $parts)))) {
                $findings[] = 'audience_snapshot_corrupt';
            }
        }
        foreach ($this->connection->iterateAssociative('SELECT email_canonical, reason, source FROM suppression') as $suppression) {
            $email = $suppression['email_canonical'] ?? null;
            $separator = \is_string($email) ? strrpos($email, '@') : false;
            if (!\is_string($email) || false === filter_var($email, FILTER_VALIDATE_EMAIL) || false === $separator
                || trim($email) !== $email || substr($email, $separator + 1) !== strtolower(substr($email, $separator + 1))) {
                $findings[] = 'suppression_inconsistent';
                break;
            }
            if ('unsubscribe' === ($suppression['reason'] ?? null) && '' === ($suppression['source'] ?? null)) {
                $findings[] = 'suppression_inconsistent';
                break;
            }
        }
    }

    /** @param list<string> $findings */
    private function checkSchema(array &$findings): void
    {
        $path = $this->projectDirectory.'/config/upgrade/schema-1.0.0.json';
        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!\is_array($expected) || !\is_array($expected['tables'] ?? null)) {
            $findings[] = 'schema_manifest_unavailable';

            return;
        }
        $actual = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION') as $row) {
            $table = $row['TABLE_NAME'];
            if (!\is_string($table) || !\is_string($row['COLUMN_NAME']) || !\is_string($row['COLUMN_TYPE'])) {
                $findings[] = 'schema_introspection_failed';

                return;
            }
            $actual[$table][] = ['name' => $row['COLUMN_NAME'], 'type' => $row['COLUMN_TYPE'], 'nullable' => 'YES' === $row['IS_NULLABLE']];
        }
        if ($actual !== $expected['tables']) {
            $findings[] = 'unknown_schema_drift';
        }
        $foreignKeys = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION') as $row) {
            $foreignKeys[] = ['table' => $row['TABLE_NAME'], 'constraint' => $row['CONSTRAINT_NAME'], 'column' => $row['COLUMN_NAME'], 'position' => is_numeric($row['ORDINAL_POSITION']) ? (int) $row['ORDINAL_POSITION'] : -1, 'referenced_table' => $row['REFERENCED_TABLE_NAME'], 'referenced_column' => $row['REFERENCED_COLUMN_NAME']];
        }
        $indexes = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX') as $row) {
            $indexes[] = ['table' => $row['TABLE_NAME'], 'index' => $row['INDEX_NAME'], 'position' => is_numeric($row['SEQ_IN_INDEX']) ? (int) $row['SEQ_IN_INDEX'] : -1, 'column' => $row['COLUMN_NAME'], 'unique' => 0 === $row['NON_UNIQUE'] || '0' === $row['NON_UNIQUE']];
        }
        $triggers = [];
        foreach ($this->connection->fetchAllAssociative('SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_TIMING FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME') as $row) {
            $triggers[] = ['name' => $row['TRIGGER_NAME'], 'event' => $row['EVENT_MANIPULATION'], 'table' => $row['EVENT_OBJECT_TABLE'], 'timing' => $row['ACTION_TIMING']];
        }
        if ($foreignKeys !== ($expected['foreign_keys'] ?? null) || $indexes !== ($expected['indexes'] ?? null) || $triggers !== ($expected['triggers'] ?? null)) {
            $findings[] = 'schema_constraint_drift';
        }
    }

    /** @param list<string> $findings */
    private function checkFilesystem(array &$findings): void
    {
        $lockFile = $this->privateDirectory.'/install.lock';
        if (!is_dir($this->privateDirectory) || is_link($this->privateDirectory) || !is_readable($this->privateDirectory) || !is_writable($this->privateDirectory)
            || !is_file($lockFile) || is_link($lockFile)
            || '' === $this->masterKeyFile || !is_file($this->masterKeyFile) || is_link($this->masterKeyFile) || (fileperms($this->masterKeyFile) & 0o077) !== 0) {
            $findings[] = 'filesystem_prerequisite_failed';

            return;
        }
        $lockStat = lstat($lockFile);
        if (false === $lockStat || 1 !== $lockStat['nlink'] || 0 !== ($lockStat['mode'] & 0o077) || $lockStat['size'] > 4096) {
            $findings[] = 'installation_lock_invalid';

            return;
        }
        $lock = json_decode((string) file_get_contents($lockFile), true);
        $databaseId = $this->connection->fetchOne("SELECT installation_uuid FROM product_identity WHERE singleton_key = '1'");
        if (!\is_array($lock) || 'emailer-light' !== ($lock['product_id'] ?? null) || '1.0.0' !== ($lock['product_version'] ?? null)
            || '20261014000000' !== ($lock['schema_version'] ?? null) || '1.0' !== ($lock['upgrade_contract_version'] ?? null)
            || !\is_string($databaseId) || $databaseId !== ($lock['installation_uuid'] ?? null)) {
            $findings[] = 'installation_lock_identity_mismatch';
        }
    }

    private function count(string $sql): int
    {
        $value = $this->connection->fetchOne($sql);
        if (!is_numeric($value)) {
            throw new \RuntimeException('Count query failed.');
        }

        return (int) $value;
    }
}
