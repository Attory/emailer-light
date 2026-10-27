<?php

declare(strict_types=1);

/** Root-only host journal. Does not contain addresses, credentials or message bodies. */
final class EmailerUpdateDeliveryError extends RuntimeException
{
}

final class EmailerUpdateDelivery
{
    public static function read(string $file, string $operation): ?array
    {
        self::parent($file);
        if (!file_exists($file) && !is_link($file)) {
            return null;
        }
        $stat = lstat($file);
        if (!is_array($stat) || !is_file($file) || is_link($file) || 0 !== $stat['uid'] || 0 !== ($stat['mode'] & 0o022) || 1 !== $stat['nlink'] || 1048576 < $stat['size']) {
            throw new EmailerUpdateDeliveryError('Update delivery evidence is unsafe.');
        }
        $state = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($state) || 'emailer-update-delivery-v1' !== ($state['format'] ?? null) || !in_array($state['phase'] ?? null, ['acquiring', 'in_progress', 'resuming', 'blocked', 'failed', 'recovered', 'complete'], true)) {
            throw new EmailerUpdateDeliveryError('Update delivery evidence is invalid.');
        }
        if ($operation !== ($state['operation'] ?? null) && 'complete' !== $state['phase']) {
            throw new EmailerUpdateDeliveryError('A different update still owns the delivery hold.');
        }
        return $operation === ($state['operation'] ?? null) ? $state : null;
    }

    public static function save(string $file, array $state): void
    {
        self::parent($file);
        $bytes = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT)."\n";
        if (1048576 < strlen($bytes)) {
            throw new EmailerUpdateDeliveryError('Update delivery evidence exceeds its safe bound.');
        }
        $temporary = $file.'.'.bin2hex(random_bytes(8)).'.tmp';
        $stream = fopen($temporary, 'x+b');
        if (false === $stream) {
            throw new EmailerUpdateDeliveryError('Update delivery evidence could not be created.');
        }
        try {
            if (!chmod($temporary, 0o644) || strlen($bytes) !== fwrite($stream, $bytes) || !fflush($stream) || !fsync($stream)) {
                throw new EmailerUpdateDeliveryError('Update delivery evidence could not be persisted.');
            }
            if (!rename($temporary, $file)) {
                throw new EmailerUpdateDeliveryError('Update delivery evidence could not be published.');
            }
            $directory = fopen(dirname($file), 'r');
            if (false === $directory) {
                throw new EmailerUpdateDeliveryError('Update evidence directory could not be opened.');
            }
            try {
                if (!fsync($directory)) {
                    throw new EmailerUpdateDeliveryError('Update evidence directory was not persisted.');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            fclose($stream);
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function parent(string $file): void
    {
        $parent = lstat(dirname($file));
        if (0 !== posix_geteuid() || 'update-maintenance.json' !== basename($file) || !is_array($parent) || is_link(dirname($file)) || 0o040000 !== ($parent['mode'] & 0o170000) || 0 !== $parent['uid'] || 0 !== ($parent['mode'] & 0o022)) {
            throw new EmailerUpdateDeliveryError('The update evidence location is not root-controlled.');
        }
    }

    public static function begin(\Doctrine\DBAL\Connection $db, string $file, string $operation): array
    {
        $existing = self::read($file, $operation);
        if (null !== $existing && 'recovered' !== $existing['phase']) {
            // A crash between journal publication and transaction commit is uncertain.
            if ('acquiring' === $existing['phase']) {
                $db->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
                throw new EmailerUpdateDeliveryError('Update hold acquisition was interrupted. Recovery is required; the prior state was not assumed.');
            }
            if ('complete' === $existing['phase']) {
                throw new EmailerUpdateDeliveryError('This update already completed; no new hold was acquired.');
            }
            $db->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            return $existing;
        }
        $state = $db->transactional(function () use ($db, $file, $operation): array {
            // Workers must acquire this same row before claiming a recipient. Holding
            // it closes the read/hold race; already committed claims are included.
            $runtime = $db->fetchAssociative("SELECT schema_version, accepting_work, active_claims FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
            if (false === $runtime || 1 !== (int) $runtime['schema_version'] || !in_array((string) $runtime['accepting_work'], ['0', '1'], true) || !is_numeric($runtime['active_claims']) || 0 > (int) $runtime['active_claims']) {
                throw new EmailerUpdateDeliveryError('Delivery state could not be determined. No delivery was announced as paused.');
            }
            $campaigns = $db->fetchAllAssociative("SELECT id, state FROM campaign WHERE state IN ('sending','queueing') ORDER BY id LIMIT 1001");
            if (1000 < count($campaigns)) {
                throw new EmailerUpdateDeliveryError('More than 1000 active campaigns require supervised maintenance.');
            }
            foreach ($campaigns as &$campaign) {
                $counts = array_fill_keys(['pending', 'retry', 'processing', 'accepted', 'suppressed', 'permanent_failure', 'ambiguous', 'cancelled'], 0);
                foreach ($db->fetchAllAssociative('SELECT state, COUNT(*) n FROM campaign_recipient WHERE campaign_id=? GROUP BY state', [$campaign['id']]) as $count) {
                    if (!array_key_exists($count['state'], $counts)) {
                        throw new EmailerUpdateDeliveryError('A delivery ledger state is unknown.');
                    }
                    $counts[$count['state']] = (int) $count['n'];
                }
                $campaign['counts'] = $counts;
                $campaign['sent'] = $counts['accepted']; // SMTP acceptance; never inbox delivery.
                $campaign['remaining'] = $counts['pending'] + $counts['retry'] + $counts['processing'];
            }
            unset($campaign);
            $inflight = $db->fetchAllAssociative("SELECT r.id recipient_id, r.campaign_id, a.id attempt_id FROM campaign_recipient r LEFT JOIN delivery_attempt a ON a.campaign_recipient_id=r.id AND a.state='started' WHERE r.state='processing' ORDER BY r.id LIMIT 1001");
            if (1000 < count($inflight)) {
                throw new EmailerUpdateDeliveryError('In-flight work exceeds the safe inspection bound.');
            }
            $tests = $db->fetchAllAssociative("SELECT id, campaign_id, state FROM campaign_test_delivery WHERE state IN ('pending','processing') ORDER BY id LIMIT 1001");
            if (1000 < count($tests)) {
                throw new EmailerUpdateDeliveryError('Pending test work exceeds the inspection bound.');
            }
            $state = ['format' => 'emailer-update-delivery-v1', 'operation' => $operation, 'installation_uuid' => $db->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'"), 'phase' => 'acquiring', 'prior_accepting_work' => (int) $runtime['accepting_work'], 'active_claims' => (int) $runtime['active_claims'], 'campaigns' => $campaigns, 'inflight' => $inflight, 'test_work' => $tests, 'captured_at' => gmdate('c')];
            self::save($file, $state);
            $db->executeStatement("UPDATE delivery_runtime SET accepting_work=0,updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'");
            return $state;
        });
        $state['phase'] = 'in_progress';
        self::save($file, $state);
        return $state;
    }
}
