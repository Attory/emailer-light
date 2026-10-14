<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Emailer Light 1.0.0 clean installation schema; no reusable contacts, roles, MFA, or security levels.';
    }

    public function up(Schema $schema): void
    {
        $databaseVersion = $this->connection->fetchOne('SELECT VERSION()');
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform || !\is_string($databaseVersion) || !str_contains(strtolower($databaseVersion), 'mariadb') || version_compare($databaseVersion, '10.11', '<'), 'Emailer Light requires MariaDB 10.11 or later.');
        $this->addSql('SET FOREIGN_KEY_CHECKS = 0');
        $this->addSql(<<<'SQL'
CREATE TABLE `analytics_aggregate` (
  `campaign_recipient_id` char(36) NOT NULL,
  `event_type` varchar(8) NOT NULL,
  `link_index` smallint(5) unsigned NOT NULL,
  `event_count` int(10) unsigned NOT NULL,
  `first_seen_at` datetime(6) NOT NULL,
  `last_seen_at` datetime(6) NOT NULL,
  PRIMARY KEY (`campaign_recipient_id`,`event_type`,`link_index`),
  KEY `idx_analytics_retention` (`last_seen_at`),
  CONSTRAINT `fk_analytics_recipient` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `campaign_recipient` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_analytics_event` CHECK (`event_type` in ('open','click')),
  CONSTRAINT `chk_analytics_link` CHECK (`event_type` = 'open' and `link_index` = 0 or `event_type` = 'click')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `app_user` (
  `id` char(36) NOT NULL,
  `singleton_key` char(1) NOT NULL,
  `login_identifier` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `active` tinyint(1) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  `password_changed_at` datetime(6) NOT NULL,
  `last_login_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_user_login` (`login_identifier`),
  UNIQUE KEY `uniq_administrator_singleton` (`singleton_key`),
  CONSTRAINT `chk_administrator_singleton` CHECK (`singleton_key` = '1'),
  CONSTRAINT `chk_administrator_active` CHECK (`active` = 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `audit_event` (
  `id` char(36) NOT NULL,
  `actor_user_id` char(36) DEFAULT NULL,
  `action` varchar(96) NOT NULL,
  `target_id` char(64) DEFAULT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `source_hash` char(64) DEFAULT NULL,
  `result` varchar(16) NOT NULL,
  `summary_json` longtext NOT NULL CHECK (json_valid(`summary_json`)),
  PRIMARY KEY (`id`),
  KEY `idx_audit_occurred` (`occurred_at`,`id`),
  KEY `idx_audit_actor` (`actor_user_id`,`occurred_at`),
  KEY `idx_audit_action` (`action`,`occurred_at`),
  CONSTRAINT `chk_audit_result` CHECK (`result` in ('success','failure','denied'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `backup_record` (
  `id` char(36) NOT NULL,
  `format_version` smallint(5) unsigned NOT NULL,
  `filename` varchar(120) NOT NULL,
  `ciphertext_sha256` char(64) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `schema_version` varchar(32) NOT NULL,
  `source_version` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `verified_at` datetime(6) DEFAULT NULL,
  `restored_at` datetime(6) DEFAULT NULL,
  `deleted_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_backup_filename` (`filename`),
  KEY `idx_backup_verified` (`verified_at`),
  CONSTRAINT `chk_backup_status` CHECK (`status` in ('created','verified','invalid','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `backup_transfer` (
  `id` char(36) NOT NULL,
  `backup_id` char(36) NOT NULL,
  `destination_type` varchar(16) NOT NULL,
  `object_name` varchar(120) NOT NULL,
  `ciphertext_sha256` char(64) NOT NULL,
  `byte_size` bigint(20) unsigned NOT NULL,
  `status` varchar(16) NOT NULL,
  `detail_code` varchar(64) NOT NULL,
  `attempted_at` datetime(6) NOT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_backup_transfer` (`backup_id`,`destination_type`),
  KEY `idx_backup_transfer_status` (`status`,`attempted_at`),
  CONSTRAINT `fk_backup_transfer_record` FOREIGN KEY (`backup_id`) REFERENCES `backup_record` (`id`),
  CONSTRAINT `chk_backup_transfer_destination` CHECK (`destination_type` = 'filesystem'),
  CONSTRAINT `chk_backup_transfer_status` CHECK (`status` in ('succeeded','failed','deleted'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign` (
  `id` char(36) NOT NULL,
  `name` varchar(160) NOT NULL,
  `template_version_id` char(36) NOT NULL,
  `state` varchar(24) NOT NULL,
  `scheduled_at` datetime(6) DEFAULT NULL,
  `approved_at` datetime(6) DEFAULT NULL,
  `approved_by` char(36) DEFAULT NULL,
  `approval_waived_test` tinyint(1) NOT NULL DEFAULT 0,
  `rsvp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `operation_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_by` char(36) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  `messages_per_minute` smallint(5) unsigned DEFAULT NULL,
  `rate_window_started_at` datetime(6) DEFAULT NULL,
  `rate_window_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_state_schedule` (`state`,`scheduled_at`),
  KEY `fk_campaign_template` (`template_version_id`),
  KEY `fk_campaign_approver` (`approved_by`),
  KEY `fk_campaign_creator` (`created_by`),
  CONSTRAINT `fk_campaign_approver` FOREIGN KEY (`approved_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_campaign_creator` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_campaign_template` FOREIGN KEY (`template_version_id`) REFERENCES `template_version` (`id`),
  CONSTRAINT `chk_campaign_state` CHECK (`state` in ('draft','review','approved','scheduled','queueing','sending','paused','completed','cancelled','failed')),
  CONSTRAINT `chk_campaign_waiver` CHECK (`approval_waived_test` in (0,1)),
  CONSTRAINT `chk_campaign_rsvp` CHECK (`rsvp_enabled` in (0,1)),
  CONSTRAINT `chk_campaign_rate` CHECK (`messages_per_minute` is null or `messages_per_minute` between 1 and 600)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign_audience_recipient` (
  `campaign_id` char(36) NOT NULL,
  `recipient_id` char(36) NOT NULL,
  `email_canonical` varchar(254) NOT NULL,
  `profile_json` longtext NOT NULL CHECK (json_valid(`profile_json`)),
  PRIMARY KEY (`campaign_id`,`recipient_id`),
  UNIQUE KEY `uniq_campaign_audience_email` (`campaign_id`,`email_canonical`),
  CONSTRAINT `fk_campaign_audience_recipient_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign_audience_snapshot` (
  `campaign_id` char(36) NOT NULL,
  `recipient_count` int(10) unsigned NOT NULL,
  `audience_sha256` char(64) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`campaign_id`),
  CONSTRAINT `fk_campaign_audience_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign_content_snapshot` (
  `campaign_id` char(36) NOT NULL,
  `template_version_id` char(36) NOT NULL,
  `subject` varchar(200) NOT NULL,
  `preheader` varchar(200) DEFAULT NULL,
  `html_body` mediumtext NOT NULL,
  `text_body` mediumtext NOT NULL,
  `unsubscribe_footer` varchar(500) NOT NULL,
  `one_click_dkim_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `merge_fields_json` longtext NOT NULL CHECK (json_valid(`merge_fields_json`)),
  `link_manifest_json` longtext NOT NULL CHECK (json_valid(`link_manifest_json`)),
  `open_tracking` tinyint(1) NOT NULL DEFAULT 0,
  `click_tracking` tinyint(1) NOT NULL DEFAULT 0,
  `rsvp_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `rsvp_repeat_policy` varchar(8) NOT NULL DEFAULT 'replace',
  `rsvp_form_schema_json` longtext NOT NULL CHECK (json_valid(`rsvp_form_schema_json`)),
  `content_sha256` char(64) NOT NULL,
  `sender_address` varchar(254) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `reply_to_address` varchar(254) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`campaign_id`),
  KEY `fk_campaign_content_version` (`template_version_id`),
  CONSTRAINT `fk_campaign_content_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_campaign_content_version` FOREIGN KEY (`template_version_id`) REFERENCES `template_version` (`id`),
  CONSTRAINT `chk_campaign_one_click_dkim` CHECK (`one_click_dkim_confirmed` in (0,1)),
  CONSTRAINT `chk_campaign_tracking` CHECK (`open_tracking` in (0,1) and `click_tracking` in (0,1)),
  CONSTRAINT `chk_campaign_snapshot_rsvp` CHECK (`rsvp_enabled` in (0,1) and `rsvp_repeat_policy` in ('first','replace'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign_recipient` (
  `id` char(36) NOT NULL,
  `campaign_id` char(36) NOT NULL,
  `recipient_id` char(36) NOT NULL,
  `state` varchar(24) NOT NULL,
  `attempt_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `next_attempt_at` datetime(6) DEFAULT NULL,
  `claimed_at` datetime(6) DEFAULT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `terminal_at` datetime(6) DEFAULT NULL,
  `message_id` varchar(190) NOT NULL,
  `operation_version` int(10) unsigned NOT NULL,
  `last_failure_class` varchar(16) DEFAULT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_campaign_recipient_delivery` (`campaign_id`,`recipient_id`),
  UNIQUE KEY `uniq_delivery_message_id` (`message_id`),
  KEY `idx_delivery_due` (`state`,`next_attempt_at`),
  KEY `idx_delivery_campaign_state` (`campaign_id`,`state`),
  CONSTRAINT `fk_delivery_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`),
  CONSTRAINT `fk_delivery_audience` FOREIGN KEY (`campaign_id`,`recipient_id`) REFERENCES `campaign_audience_recipient` (`campaign_id`,`recipient_id`),
  CONSTRAINT `chk_delivery_state` CHECK (`state` in ('pending','processing','retry','accepted','suppressed','permanent_failure','ambiguous','cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `campaign_test_delivery` (
  `id` char(36) NOT NULL,
  `campaign_id` char(36) NOT NULL,
  `requested_by` char(36) DEFAULT NULL,
  `recipient_address` varchar(254) NOT NULL,
  `state` varchar(16) NOT NULL,
  `failure_class` varchar(16) DEFAULT NULL,
  `requested_at` datetime(6) NOT NULL,
  `accepted_at` datetime(6) DEFAULT NULL,
  `draft_version` int(10) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_campaign_test_campaign` (`campaign_id`,`requested_at`),
  KEY `fk_campaign_test_actor` (`requested_by`),
  CONSTRAINT `fk_campaign_test_actor` FOREIGN KEY (`requested_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_campaign_test_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_campaign_test_state` CHECK (`state` in ('pending','processing','accepted','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `delivery_attempt` (
  `id` char(36) NOT NULL,
  `campaign_recipient_id` char(36) NOT NULL,
  `attempt_number` smallint(5) unsigned NOT NULL,
  `state` varchar(16) NOT NULL,
  `failure_class` varchar(16) DEFAULT NULL,
  `provider_reference_hash` char(64) DEFAULT NULL,
  `started_at` datetime(6) NOT NULL,
  `finished_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_delivery_attempt_number` (`campaign_recipient_id`,`attempt_number`),
  KEY `idx_delivery_attempt_open` (`state`,`started_at`),
  CONSTRAINT `fk_attempt_recipient` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `campaign_recipient` (`id`),
  CONSTRAINT `chk_attempt_state` CHECK (`state` in ('started','accepted','transient','permanent','ambiguous')),
  CONSTRAINT `chk_attempt_failure` CHECK (`failure_class` is null or `failure_class` in ('transient','permanent','ambiguous'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `delivery_domain_rate` (
  `domain_hash` char(64) NOT NULL,
  `window_started_at` datetime(6) NOT NULL,
  `message_count` int(10) unsigned NOT NULL DEFAULT 0,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`domain_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `delivery_policy` (
  `singleton_key` char(1) NOT NULL,
  `messages_per_minute` smallint(5) unsigned NOT NULL,
  `worker_concurrency` smallint(5) unsigned NOT NULL,
  `retry_limit` smallint(5) unsigned NOT NULL,
  `maximum_campaign_size` int(10) unsigned NOT NULL,
  `maximum_csv_bytes` int(10) unsigned NOT NULL,
  `maximum_csv_rows` int(10) unsigned NOT NULL,
  `worker_mode` varchar(16) NOT NULL,
  `worker_available` tinyint(1) NOT NULL,
  `scheduler_available` tinyint(1) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  CONSTRAINT `chk_delivery_singleton` CHECK (`singleton_key` = '1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `delivery_runtime` (
  `singleton_key` char(1) NOT NULL,
  `schema_version` int(10) unsigned NOT NULL,
  `accepting_work` tinyint(1) NOT NULL,
  `active_claims` smallint(5) unsigned NOT NULL DEFAULT 0,
  `rate_window_started_at` datetime(6) NOT NULL,
  `rate_window_count` int(10) unsigned NOT NULL DEFAULT 0,
  `circuit_state` varchar(16) NOT NULL,
  `circuit_failures` smallint(5) unsigned NOT NULL DEFAULT 0,
  `circuit_open_until` datetime(6) DEFAULT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  CONSTRAINT `chk_delivery_runtime_singleton` CHECK (`singleton_key` = '1'),
  CONSTRAINT `chk_delivery_accepting` CHECK (`accepting_work` in (0,1)),
  CONSTRAINT `chk_delivery_circuit` CHECK (`circuit_state` in ('closed','open','half_open'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `email_template` (
  `id` char(36) NOT NULL,
  `name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) NOT NULL,
  `published_version_id` char(36) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  `archived_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_email_template_name` (`name`),
  KEY `idx_email_template_status` (`status`,`updated_at`),
  KEY `fk_template_published_version` (`published_version_id`),
  CONSTRAINT `fk_template_published_version` FOREIGN KEY (`published_version_id`) REFERENCES `template_version` (`id`),
  CONSTRAINT `chk_email_template_status` CHECK (`status` in ('draft','published','archived'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `encrypted_secret` (
  `id` char(36) NOT NULL,
  `record_type` varchar(64) NOT NULL,
  `envelope` longtext NOT NULL,
  `key_version` smallint(5) unsigned NOT NULL,
  `schema_version` smallint(5) unsigned NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `rotated_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_encrypted_secret_type` (`record_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `health_check_run` (
  `id` char(36) NOT NULL,
  `run_type` varchar(16) NOT NULL DEFAULT 'full',
  `trigger_source` varchar(16) NOT NULL DEFAULT 'manual',
  `status` varchar(16) NOT NULL,
  `critical_count` smallint(5) unsigned NOT NULL,
  `warning_count` smallint(5) unsigned NOT NULL,
  `findings_json` longtext NOT NULL CHECK (json_valid(`findings_json`)),
  `started_at` datetime(6) NOT NULL,
  `finished_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_health_run_finished` (`finished_at`),
  KEY `idx_health_run_type_finished` (`run_type`,`finished_at`),
  CONSTRAINT `chk_health_status` CHECK (`status` in ('healthy','warning','critical')),
  CONSTRAINT `chk_health_run_type` CHECK (`run_type` in ('lightweight','full')),
  CONSTRAINT `chk_health_trigger` CHECK (`trigger_source` in ('login','manual','daily','update'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `health_finding_state` (
  `code` varchar(64) NOT NULL,
  `severity` varchar(16) NOT NULL,
  `summary` varchar(240) NOT NULL,
  `risk` varchar(240) NOT NULL,
  `evidence` varchar(240) NOT NULL,
  `remediation` varchar(240) NOT NULL,
  `sending_blocked` tinyint(1) NOT NULL,
  `active` tinyint(1) NOT NULL,
  `first_observed_at` datetime(6) NOT NULL,
  `last_observed_at` datetime(6) NOT NULL,
  `last_run_id` char(36) DEFAULT NULL,
  `acknowledged_at` datetime(6) DEFAULT NULL,
  `acknowledged_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`code`),
  KEY `idx_health_finding_active` (`active`,`severity`,`last_observed_at`),
  KEY `fk_health_finding_run` (`last_run_id`),
  KEY `fk_health_finding_actor` (`acknowledged_by`),
  CONSTRAINT `fk_health_finding_actor` FOREIGN KEY (`acknowledged_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_health_finding_run` FOREIGN KEY (`last_run_id`) REFERENCES `health_check_run` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_health_finding_severity` CHECK (`severity` in ('critical','warning')),
  CONSTRAINT `chk_health_finding_flags` CHECK (`sending_blocked` in (0,1) and `active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `housekeeping_run` (
  `id` char(36) NOT NULL,
  `dry_run` tinyint(1) NOT NULL,
  `deleted_sessions` int(10) unsigned NOT NULL,
  `deleted_queue_rows` int(10) unsigned NOT NULL,
  `deleted_dead_letter_rows` int(10) unsigned NOT NULL,
  `deleted_delivery_attempt_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted_health_runs` int(10) unsigned NOT NULL,
  `deleted_analytics_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted_rsvp_rows` int(10) unsigned NOT NULL DEFAULT 0,
  `deleted_backup_files` int(10) unsigned NOT NULL DEFAULT 0,
  `status` varchar(16) NOT NULL,
  `started_at` datetime(6) NOT NULL,
  `finished_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_housekeeping_finished` (`finished_at`),
  CONSTRAINT `chk_housekeeping_dry_run` CHECK (`dry_run` in (0,1)),
  CONSTRAINT `chk_housekeeping_status` CHECK (`status` in ('succeeded','failed'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `installation_state` (
  `singleton_key` char(1) NOT NULL,
  `installation_uuid` char(36) DEFAULT NULL,
  `database_ready_at` datetime(6) DEFAULT NULL,
  `key_ready_at` datetime(6) DEFAULT NULL,
  `organisation_ready_at` datetime(6) DEFAULT NULL,
  `administrator_ready_at` datetime(6) DEFAULT NULL,
  `mail_ready_at` datetime(6) DEFAULT NULL,
  `policy_ready_at` datetime(6) DEFAULT NULL,
  `capability_ready_at` datetime(6) DEFAULT NULL,
  `completed_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`singleton_key`),
  UNIQUE KEY `uniq_installation_uuid` (`installation_uuid`),
  CONSTRAINT `chk_installation_singleton` CHECK (`singleton_key` = '1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `login_throttle` (
  `scope_hash` char(64) NOT NULL,
  `scope_type` varchar(16) NOT NULL,
  `failure_count` smallint(5) unsigned NOT NULL,
  `pending_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `pending_updated_at` datetime(6) DEFAULT NULL,
  `first_failed_at` datetime(6) NOT NULL,
  `last_failed_at` datetime(6) NOT NULL,
  `not_before` datetime(6) NOT NULL,
  `locked_until` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`scope_hash`),
  KEY `idx_login_throttle_expiry` (`locked_until`,`last_failed_at`),
  KEY `idx_throttle_reclaim` (`pending_count`,`last_failed_at`,`locked_until`),
  CONSTRAINT `chk_login_throttle_type` CHECK (`scope_type` in ('account','source'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `messenger_messages` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `body` longtext NOT NULL,
  `headers` longtext NOT NULL,
  `queue_name` varchar(190) NOT NULL,
  `created_at` datetime NOT NULL,
  `available_at` datetime NOT NULL,
  `delivered_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_messenger_queue` (`queue_name`,`available_at`,`delivered_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `operations_policy` (
  `singleton_key` char(1) NOT NULL,
  `doctor_interval_hours` smallint(5) unsigned NOT NULL,
  `retention_automation_enabled` tinyint(1) NOT NULL,
  `retention_interval_hours` smallint(5) unsigned NOT NULL,
  `revoked_session_retention_days` smallint(5) unsigned NOT NULL,
  `queue_retention_days` smallint(5) unsigned NOT NULL,
  `dead_letter_retention_days` smallint(5) unsigned NOT NULL,
  `health_retention_days` smallint(5) unsigned NOT NULL,
  `delivery_attempt_retention_days` smallint(5) unsigned NOT NULL,
  `log_retention_days` smallint(5) unsigned NOT NULL,
  `backup_automation_enabled` tinyint(1) NOT NULL,
  `backup_interval_hours` smallint(5) unsigned NOT NULL,
  `backup_retention_days` smallint(5) unsigned NOT NULL,
  `restore_drill_interval_days` smallint(5) unsigned NOT NULL,
  `backup_destination_type` varchar(16) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  CONSTRAINT `chk_operations_singleton` CHECK (`singleton_key` = '1'),
  CONSTRAINT `chk_operations_flags` CHECK (`retention_automation_enabled` in (0,1) and `backup_automation_enabled` in (0,1)),
  CONSTRAINT `chk_operations_intervals` CHECK (`doctor_interval_hours` between 1 and 168 and `retention_interval_hours` between 1 and 168 and `backup_interval_hours` between 1 and 720),
  CONSTRAINT `chk_operations_retention` CHECK (`revoked_session_retention_days` between 7 and 365 and `queue_retention_days` between 1 and 90 and `dead_letter_retention_days` between 30 and 730 and `health_retention_days` between 30 and 730 and `delivery_attempt_retention_days` between 30 and 730 and `log_retention_days` between 1 and 365 and `backup_retention_days` between 2 and 365 and `restore_drill_interval_days` between 7 and 365),
  CONSTRAINT `chk_backup_destination_type` CHECK (`backup_destination_type` in ('none','filesystem'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `operations_run` (
  `id` char(36) NOT NULL,
  `operation` varchar(16) NOT NULL,
  `status` varchar(16) NOT NULL,
  `detail_code` varchar(64) NOT NULL,
  `started_at` datetime(6) NOT NULL,
  `finished_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_operations_run_due` (`operation`,`status`,`finished_at`),
  CONSTRAINT `chk_operations_run_operation` CHECK (`operation` in ('doctor','retention','backup')),
  CONSTRAINT `chk_operations_run_status` CHECK (`status` in ('succeeded','failed','skipped'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `organisation_settings` (
  `singleton_key` char(1) NOT NULL,
  `installation_uuid` char(36) NOT NULL,
  `display_name` varchar(160) NOT NULL,
  `canonical_base_url` varchar(2048) NOT NULL,
  `locale` varchar(16) NOT NULL,
  `time_zone` varchar(64) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  UNIQUE KEY `uniq_organisation_installation` (`installation_uuid`),
  CONSTRAINT `chk_organisation_singleton` CHECK (`singleton_key` = '1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `privacy_policy` (
  `singleton_key` char(1) NOT NULL,
  `open_tracking` tinyint(1) NOT NULL,
  `click_tracking` tinyint(1) NOT NULL,
  `rsvp_enabled` tinyint(1) NOT NULL,
  `operational_retention_days` smallint(5) unsigned NOT NULL,
  `delivery_retention_days` smallint(5) unsigned NOT NULL,
  `tracking_retention_days` smallint(5) unsigned NOT NULL DEFAULT 90,
  `rsvp_retention_days` smallint(5) unsigned NOT NULL DEFAULT 90,
  `rsvp_repeat_policy` varchar(8) NOT NULL DEFAULT 'replace',
  `suppression_mode` varchar(16) NOT NULL,
  `unsubscribe_footer` varchar(500) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  CONSTRAINT `chk_privacy_singleton` CHECK (`singleton_key` = '1'),
  CONSTRAINT `chk_tracking_retention` CHECK (`tracking_retention_days` between 1 and 365),
  CONSTRAINT `chk_privacy_features` CHECK (`open_tracking` in (0,1) and `click_tracking` in (0,1) and `rsvp_enabled` in (0,1)),
  CONSTRAINT `chk_rsvp_retention` CHECK (`rsvp_retention_days` between 1 and 365),
  CONSTRAINT `chk_rsvp_repeat` CHECK (`rsvp_repeat_policy` in ('first','replace'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `recipient_token` (
  `id` char(36) NOT NULL,
  `handle` char(43) NOT NULL,
  `campaign_id` char(36) NOT NULL,
  `campaign_recipient_id` char(36) NOT NULL,
  `recipient_id` char(36) NOT NULL,
  `email_canonical` varchar(254) DEFAULT NULL,
  `purpose` varchar(32) NOT NULL,
  `key_version` smallint(5) unsigned NOT NULL,
  `issued_at` datetime(6) NOT NULL,
  `expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `last_used_at` datetime(6) DEFAULT NULL,
  `use_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_recipient_token_handle` (`handle`),
  UNIQUE KEY `uniq_recipient_token_scope` (`campaign_recipient_id`,`purpose`),
  KEY `idx_recipient_token_retention` (`expires_at`,`revoked_at`),
  KEY `fk_recipient_token_campaign` (`campaign_id`),
  KEY `fk_recipient_token_key` (`key_version`),
  CONSTRAINT `fk_recipient_token_campaign` FOREIGN KEY (`campaign_id`) REFERENCES `campaign` (`id`),
  CONSTRAINT `fk_recipient_token_delivery` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `campaign_recipient` (`id`),
  CONSTRAINT `fk_recipient_token_key` FOREIGN KEY (`key_version`) REFERENCES `recipient_token_key` (`key_version`),
  CONSTRAINT `fk_token_audience` FOREIGN KEY (`campaign_id`,`recipient_id`) REFERENCES `campaign_audience_recipient` (`campaign_id`,`recipient_id`),
  CONSTRAINT `chk_recipient_token_purpose` CHECK (`purpose` in ('unsubscribe','view','open','click','rsvp'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `recipient_token_key` (
  `key_version` smallint(5) unsigned NOT NULL,
  `secret_id` char(36) NOT NULL,
  `active` tinyint(1) NOT NULL,
  `verify_until` datetime(6) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`key_version`),
  UNIQUE KEY `uniq_recipient_token_secret` (`secret_id`),
  KEY `idx_recipient_token_key_retention` (`active`,`verify_until`),
  CONSTRAINT `fk_recipient_token_key_secret` FOREIGN KEY (`secret_id`) REFERENCES `encrypted_secret` (`id`),
  CONSTRAINT `chk_recipient_token_key_active` CHECK (`active` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `recipient_token_key_state` (
  `singleton_key` char(1) NOT NULL,
  `current_version` smallint(5) unsigned NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  CONSTRAINT `chk_recipient_key_state_singleton` CHECK (`singleton_key` = '1')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `release_operation` (
  `id` char(36) NOT NULL,
  `operation` varchar(16) NOT NULL,
  `source_version` varchar(32) DEFAULT NULL,
  `target_version` varchar(32) DEFAULT NULL,
  `package_sha256` char(64) DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `detail_code` varchar(64) NOT NULL,
  `started_at` datetime(6) NOT NULL,
  `finished_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_release_operation_started` (`started_at`),
  CONSTRAINT `chk_release_operation` CHECK (`operation` in ('inspect','install','update','rollback')),
  CONSTRAINT `chk_release_status` CHECK (`status` in ('started','succeeded','failed','rolled_back'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `rsvp_response` (
  `campaign_recipient_id` char(36) NOT NULL,
  `response_choice` varchar(5) NOT NULL,
  `guest_count` tinyint(3) unsigned NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `submission_count` smallint(5) unsigned NOT NULL DEFAULT 1,
  `first_submitted_at` datetime(6) NOT NULL,
  `last_submitted_at` datetime(6) NOT NULL,
  PRIMARY KEY (`campaign_recipient_id`),
  KEY `idx_rsvp_retention` (`last_submitted_at`),
  CONSTRAINT `fk_rsvp_recipient` FOREIGN KEY (`campaign_recipient_id`) REFERENCES `campaign_recipient` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_rsvp_choice` CHECK (`response_choice` in ('yes','no','maybe')),
  CONSTRAINT `chk_rsvp_guests` CHECK (`guest_count` between 0 and 20),
  CONSTRAINT `chk_rsvp_submissions` CHECK (`submission_count` >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `sender_identity` (
  `singleton_key` char(1) NOT NULL,
  `transport_type` varchar(16) NOT NULL,
  `smtp_host` varchar(255) NOT NULL,
  `smtp_port` smallint(5) unsigned NOT NULL,
  `encryption_mode` varchar(16) NOT NULL,
  `smtp_username` varchar(254) DEFAULT NULL,
  `credential_secret_id` char(36) DEFAULT NULL,
  `sender_address` varchar(254) NOT NULL,
  `sender_name` varchar(160) NOT NULL,
  `reply_to_address` varchar(254) DEFAULT NULL,
  `tls_peer_verification` tinyint(1) NOT NULL,
  `one_click_dkim_confirmed` tinyint(1) NOT NULL DEFAULT 0,
  `test_message_at` datetime(6) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `updated_at` datetime(6) NOT NULL,
  PRIMARY KEY (`singleton_key`),
  UNIQUE KEY `uniq_sender_credential` (`credential_secret_id`),
  CONSTRAINT `fk_sender_secret` FOREIGN KEY (`credential_secret_id`) REFERENCES `encrypted_secret` (`id`),
  CONSTRAINT `chk_sender_singleton` CHECK (`singleton_key` = '1'),
  CONSTRAINT `chk_sender_transport` CHECK (`transport_type` = 'smtp' and (`smtp_host` in ('mailpit','127.0.0.1','::1') and `smtp_port` = 1025 and `encryption_mode` = 'none' and `smtp_username` is null and `credential_secret_id` is null and `tls_peer_verification` = 0 or `encryption_mode` in ('starttls','tls') and `smtp_username` is not null and `credential_secret_id` is not null and `tls_peer_verification` = 1)),
  CONSTRAINT `chk_sender_one_click_dkim` CHECK (`one_click_dkim_confirmed` in (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `setup_installation_event` (
  `id` char(36) NOT NULL,
  `administrator_id` char(36) NOT NULL,
  `action` varchar(64) NOT NULL,
  `occurred_at` datetime(6) NOT NULL,
  `result` varchar(16) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_setup_completion_action` (`action`),
  KEY `fk_setup_event_administrator` (`administrator_id`),
  CONSTRAINT `fk_setup_event_administrator` FOREIGN KEY (`administrator_id`) REFERENCES `app_user` (`id`),
  CONSTRAINT `chk_setup_event_action` CHECK (`action` = 'installation.completed')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `suppression` (
  `id` char(36) NOT NULL,
  `email_canonical` varchar(254) NOT NULL,
  `email_match_key` varchar(254) GENERATED ALWAYS AS (lower(`email_canonical`)) STORED,
  `reason` varchar(32) NOT NULL,
  `source` varchar(64) NOT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_suppression_email` (`email_canonical`),
  UNIQUE KEY `uniq_suppression_match_key` (`email_match_key`),
  CONSTRAINT `chk_suppression_reason` CHECK (`reason` in ('manual','unsubscribe','complaint','hard_bounce','imported'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `template_asset` (
  `id` char(36) NOT NULL,
  `filename` varchar(80) NOT NULL,
  `media_type` varchar(32) NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `width` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `content_sha256` char(64) NOT NULL,
  `created_by` char(36) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_asset_filename` (`filename`),
  KEY `fk_template_asset_actor` (`created_by`),
  CONSTRAINT `fk_template_asset_actor` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_template_asset_media` CHECK (`media_type` in ('image/jpeg','image/png','image/webp'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `template_version` (
  `id` char(36) NOT NULL,
  `template_id` char(36) NOT NULL,
  `version_number` int(10) unsigned NOT NULL,
  `subject` varchar(200) NOT NULL,
  `preheader` varchar(200) DEFAULT NULL,
  `html_body` mediumtext NOT NULL,
  `text_body` mediumtext NOT NULL,
  `merge_fields_json` longtext NOT NULL CHECK (json_valid(`merge_fields_json`)),
  `link_manifest_json` longtext NOT NULL CHECK (json_valid(`link_manifest_json`)),
  `content_sha256` char(64) NOT NULL,
  `created_by` char(36) DEFAULT NULL,
  `created_at` datetime(6) NOT NULL,
  `published_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_template_version` (`template_id`,`version_number`),
  KEY `idx_template_version_created` (`template_id`,`created_at`),
  KEY `fk_template_version_actor` (`created_by`),
  CONSTRAINT `fk_template_version_actor` FOREIGN KEY (`created_by`) REFERENCES `app_user` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_template_version_template` FOREIGN KEY (`template_id`) REFERENCES `email_template` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `user_session` (
  `id_hash` char(64) NOT NULL,
  `user_id` char(36) DEFAULT NULL,
  `payload` mediumblob NOT NULL,
  `created_at` datetime(6) NOT NULL,
  `last_seen_at` datetime(6) NOT NULL,
  `idle_expires_at` datetime(6) NOT NULL,
  `absolute_expires_at` datetime(6) NOT NULL,
  `revoked_at` datetime(6) DEFAULT NULL,
  `source_hash` char(64) DEFAULT NULL,
  `user_agent_hash` char(64) DEFAULT NULL,
  PRIMARY KEY (`id_hash`),
  KEY `idx_user_session_user_active` (`user_id`,`revoked_at`,`last_seen_at`),
  KEY `idx_user_session_expiry` (`idle_expires_at`,`absolute_expires_at`),
  KEY `idx_session_reclaim` (`revoked_at`,`absolute_expires_at`),
  CONSTRAINT `fk_user_session_user` FOREIGN KEY (`user_id`) REFERENCES `app_user` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql(<<<'SQL'
CREATE TABLE `worker_heartbeat` (
  `worker_id` varchar(100) NOT NULL,
  `worker_kind` varchar(16) NOT NULL,
  `schema_version` int(10) unsigned NOT NULL,
  `started_at` datetime(6) NOT NULL,
  `heartbeat_at` datetime(6) NOT NULL,
  `stopped_at` datetime(6) DEFAULT NULL,
  PRIMARY KEY (`worker_id`),
  KEY `idx_worker_heartbeat` (`worker_kind`,`heartbeat_at`),
  CONSTRAINT `chk_worker_kind` CHECK (`worker_kind` in ('worker','scheduler'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin;
SQL);
        $this->addSql('SET FOREIGN_KEY_CHECKS = 1');
        $this->addSql("INSERT INTO installation_state (singleton_key, database_ready_at) VALUES ('1', UTC_TIMESTAMP(6))");
        $this->addSql("INSERT INTO operations_policy VALUES ('1', 24, 0, 24, 30, 7, 90, 90, 90, 30, 0, 24, 30, 90, 'none', UTC_TIMESTAMP(6))");
        $this->addSql("INSERT INTO recipient_token_key_state (singleton_key, current_version, updated_at) VALUES ('1', 0, UTC_TIMESTAMP(6))");
        $this->addSql("INSERT INTO delivery_runtime (singleton_key, schema_version, accepting_work, active_claims, rate_window_started_at, rate_window_count, circuit_state, circuit_failures, updated_at) VALUES ('1', 1, 1, 0, UTC_TIMESTAMP(6), 0, 'closed', 0, UTC_TIMESTAMP(6))");
        $this->addSql(<<<'SQL'
CREATE TABLE product_identity (
  singleton_key CHAR(1) NOT NULL PRIMARY KEY,
  product_id VARCHAR(32) NOT NULL,
  product_version VARCHAR(16) NOT NULL,
  schema_version VARCHAR(14) NOT NULL,
  upgrade_contract_version VARCHAR(16) NOT NULL,
  installation_uuid CHAR(36) DEFAULT NULL,
  created_at DATETIME(6) NOT NULL,
  updated_at DATETIME(6) NOT NULL,
  CONSTRAINT chk_product_singleton CHECK (singleton_key = '1'),
  CONSTRAINT chk_product_id CHECK (product_id = 'emailer-light')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
SQL);
        $this->addSql("INSERT INTO product_identity (singleton_key, product_id, product_version, schema_version, upgrade_contract_version, created_at, updated_at) VALUES ('1', 'emailer-light', '1.0.0', '20261014000000', '1.0', UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))");
        $this->addSql("CREATE TRIGGER audience_no_insert_after_snapshot BEFORE INSERT ON campaign_audience_recipient FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM campaign_audience_snapshot WHERE campaign_id = NEW.campaign_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved campaign recipients are immutable'; END IF; END");
        $this->addSql("CREATE TRIGGER audience_no_update_after_snapshot BEFORE UPDATE ON campaign_audience_recipient FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM campaign_audience_snapshot WHERE campaign_id = OLD.campaign_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved campaign recipients are immutable'; END IF; END");
        $this->addSql("CREATE TRIGGER audience_no_delete_after_snapshot BEFORE DELETE ON campaign_audience_recipient FOR EACH ROW BEGIN IF EXISTS (SELECT 1 FROM campaign_audience_snapshot WHERE campaign_id = OLD.campaign_id) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Approved campaign recipients are immutable'; END IF; END");
        $this->addSql("CREATE TRIGGER audit_event_no_update BEFORE UPDATE ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
        $this->addSql("CREATE TRIGGER audit_event_no_delete BEFORE DELETE ON audit_event FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Audit events are append-only'");
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Emailer Light storage is forward-only. Restore a verified backup.');
    }
}
