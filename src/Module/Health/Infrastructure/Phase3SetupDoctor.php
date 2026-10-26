<?php

declare(strict_types=1);

namespace App\Module\Health\Infrastructure;

use App\Module\Health\Application\Contract\SetupDoctor;
use App\Module\Secrets\Application\Contract\MasterKey;
use Doctrine\DBAL\Connection;

final readonly class Phase3SetupDoctor implements SetupDoctor
{
    private const TABLES = [
        'installation_state',
        'organisation_settings',
        'encrypted_secret',
        'app_user',
        'product_identity',
        'sender_identity',
        'delivery_policy',
        'privacy_policy',
        'setup_installation_event',
    ];

    public function __construct(
        private Connection $connection,
        private MasterKey $masterKey,
    ) {
    }

    public function criticalFailures(): array
    {
        $failures = [];
        try {
            if (!$this->connection->createSchemaManager()->tablesExist(self::TABLES)) {
                return ['database_schema'];
            }

            $state = $this->connection->fetchAssociative("SELECT * FROM installation_state WHERE singleton_key = '1'");
            if (false === $state || !\is_string($state['installation_uuid'])) {
                $failures[] = 'installation_identity';
            } else {
                foreach (['database_ready_at', 'key_ready_at', 'organisation_ready_at', 'administrator_ready_at', 'mail_ready_at', 'policy_ready_at', 'capability_ready_at'] as $column) {
                    if (null === $state[$column]) {
                        $failures[] = 'setup_steps';
                        break;
                    }
                }
            }

            $key = $this->masterKey->load();
            sodium_memzero($key);

            if (1 !== $this->count("SELECT COUNT(*) FROM organisation_settings WHERE singleton_key = '1'")) {
                $failures[] = 'organisation';
            }
            if (1 !== $this->count("SELECT COUNT(*) FROM app_user WHERE singleton_key = '1' AND active = 1")) {
                $failures[] = 'administrator';
            }
            if (1 !== $this->count('SELECT COUNT(*) FROM app_user')) {
                $failures[] = 'administrator_uniqueness';
            }
            if (1 !== $this->count("SELECT COUNT(*) FROM product_identity WHERE singleton_key = '1' AND product_id = 'emailer-light' AND product_version IN ('1.0.0', '1.1.0', '1.2.0', '1.2.1', '1.2.2', '1.2.3', '1.2.4', '1.2.5', '1.2.6', '1.2.7') AND schema_version = '20261025000000' AND upgrade_contract_version = '1.0'")) {
                $failures[] = 'product_identity';
            }
            // An absent transport with a recorded mail decision means explicitly deferred SMTP.
            // Normal sending readiness still requires a secure, configured transport.
            if (0 !== $this->count('SELECT COUNT(*) FROM sender_identity') && 1 !== $this->count(<<<'SQL'
SELECT COUNT(*) FROM sender_identity
WHERE singleton_key = '1'
  AND smtp_host IN ('mailpit', '127.0.0.1', '::1')
  AND smtp_port = 1025
  AND encryption_mode = 'none'
  AND smtp_username IS NULL
  AND credential_secret_id IS NULL
  AND tls_peer_verification = 0
  AND test_message_at IS NOT NULL
SQL)) {
                $failures[] = 'mail';
            }
            if (1 !== $this->count("SELECT COUNT(*) FROM privacy_policy WHERE singleton_key = '1' AND open_tracking = 0 AND click_tracking = 0 AND rsvp_enabled = 0 AND suppression_mode = 'global'")) {
                $failures[] = 'privacy';
            }
            if (1 !== $this->count("SELECT COUNT(*) FROM delivery_policy WHERE singleton_key = '1' AND worker_available = 1 AND scheduler_available = 1")) {
                $failures[] = 'capability';
            }
        } catch (\Throwable) {
            $failures[] = 'doctor_error';
        }

        return array_values(array_unique($failures));
    }

    private function count(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);
        if (!\is_int($count) && !\is_string($count)) {
            throw new \RuntimeException('Setup preflight count is invalid.');
        }

        return (int) $count;
    }
}
