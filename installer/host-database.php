<?php

declare(strict_types=1);

// Root-only host helper. All credentials arrive through protected stdin, never argv or diagnostics.
if (0 !== posix_geteuid()) {
    exit(1);
}
try {
    $input = json_decode((string) stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new RuntimeException();
    }
    require $input['bootstrap'].'/vendor/autoload.php';
    $connection = \Doctrine\DBAL\DriverManager::getConnection((new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($input['maintenance_url']));
    if (in_array($input['action'], ['control-begin', 'control-transfer', 'control-source', 'control-complete', 'control-recovered', 'control-review-create', 'control-review-ack', 'control-failed', 'control-target-ready'], true)) {
        $private = $input['private_directory'];
        $installation = $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (!is_string($private) || !is_string($installation) || !is_string($input['control_operation']) || !is_string($input['control_proof'])) {
            throw new RuntimeException();
        }
        $control = new \App\Shared\DeliveryControlFiles($private, $installation);
        $user = parse_url($input['source_url'], PHP_URL_USER);
        $locks = is_string($user) ? $connection->fetchFirstColumn("SELECT JSON_VALUE(Priv, '$.account_locked') FROM mysql.global_priv WHERE User=?", [rawurldecode($user)]) : [];
        $legacyBlocked = 1 === count($locks) && in_array($locks[0], [true, 1, '1', 'true'], true);
        if ('control-target-ready' === $input['action']) {
            $hold = $control->operationState($input['control_operation']);
            if (!$legacyBlocked || 'host' !== $hold['kind'] || ['proof_path' => $input['control_proof']] !== $hold['evidence']) {
                throw new RuntimeException('The original source account is not uniquely fenced.');
            }
            $control->phase($input['control_operation'], 'handoff');
        } elseif ('control-begin' === $input['action']) {
            (new \App\Module\Release\Application\InstallerDeliveryControl($connection, $private))->beginHost($input['control_operation'], $input['control_proof'], $input['control_uid'], $input['control_gid'], $input['control_invocation'], $legacyBlocked);
        } elseif (in_array($input['action'], ['control-transfer', 'control-source'], true)) {
            $control->transferOwner($input['control_uid'], $input['control_gid']);
        } elseif ('control-review-create' === $input['action']) {
            (new \App\Module\Release\Application\InstallerDeliveryControl($connection, $private))->retainDeliveryReview($input['control_operation'], $input['control_proof']);
        } elseif ('control-review-ack' === $input['action']) {
            (new \App\Module\Release\Application\InstallerDeliveryControl($connection, $private))->acknowledgeDeliveryReview($input['control_operation'], $input['control_proof']);
        } elseif ('control-failed' === $input['action']) {
            $control->phase($input['control_operation'], 'failed');
        } elseif ('control-recovered' === $input['action']) {
            $control->phase($input['control_operation'], 'recovered');
        } else {
            (new \App\Module\Release\Application\InstallerDeliveryControl($connection, $private))->completeHost($input['control_operation'], $input['control_proof']);
        }
        $state = $control->status();
        echo json_encode(['owner' => $state['owner'], 'generation' => $state['generation'], 'remaining_holds' => count($state['holds'])], JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if ('verify-endpoint' === $input['action'] && isset($input['expected_endpoint'])) {
        $proof = $input['expected_endpoint'];
        $observed = $connection->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d');
        if (!\is_array($proof) || ($proof['source_url_sha256'] ?? null) !== hash('sha256', $input['source_url']) || ($proof['server'] ?? null) !== $observed) {
            throw new RuntimeException();
        }
        echo json_encode($proof, JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (\in_array($input['action'], ['verify-endpoint', 'drain'], true)) {
        $parameters = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($input['source_url']);
        $application = \Doctrine\DBAL\DriverManager::getConnection($parameters);
        try {
            if ($application->getDatabase() !== $connection->getDatabase() || $application->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d') !== $connection->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d')) {
                throw new RuntimeException();
            }
        } finally {
            $application->close();
        }
        if ('verify-endpoint' === $input['action']) {
            echo json_encode(['source_url_sha256' => hash('sha256', $input['source_url']), 'server' => $connection->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d')], JSON_THROW_ON_ERROR)."\n";
            exit(0);
        }
    }
    if ('begin-update' === $input['action']) {
        require __DIR__.'/update-delivery.php';
        echo json_encode(EmailerUpdateDelivery::begin($connection, $input['state_file'], $input['operation']), JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if (in_array($input['action'], ['inspect-update', 'restore-idle-update', 'retain-update-hold'], true)) {
        require __DIR__.'/update-delivery.php';
        $saved = EmailerUpdateDelivery::read($input['state_file'], $input['operation']);
        if (null === $saved || $saved['installation_uuid'] !== $connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'")) {
            throw new RuntimeException();
        }
        if ('retain-update-hold' === $input['action']) {
            $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0 WHERE singleton_key='1'");
            echo "held\n";
            exit(0);
        }
        $inspection = $connection->transactional(function () use ($connection, $input, $saved): array {
            $runtime = $connection->fetchAssociative("SELECT active_claims, accepting_work FROM delivery_runtime WHERE singleton_key='1' FOR UPDATE");
            if (false === $runtime || !is_numeric($runtime['active_claims']) || !in_array((string) $runtime['accepting_work'], ['0', '1'], true)) {
                throw new RuntimeException();
            }
            $uncertain = 0;
            foreach ($saved['inflight'] as $work) {
                $uncertain += (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_recipient WHERE id=? AND state='ambiguous'", [$work['recipient_id']]);
            }
            foreach ($saved['test_work'] ?? [] as $work) {
                $uncertain += (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_test_delivery WHERE id=? AND state='failed' AND failure_class='ambiguous'", [$work['id']]);
            }
            $result = ['accepting_work' => (int) $runtime['accepting_work'], 'active_claims' => (int) $runtime['active_claims'], 'processing' => (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_recipient WHERE state='processing'"), 'test_processing' => (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_test_delivery WHERE state='processing'"), 'uncertain' => $uncertain];
            if ('restore-idle-update' === $input['action']) {
                if ('resuming' !== $saved['phase'] || 1 !== $saved['prior_accepting_work'] || 0 !== $saved['active_claims'] || 0 !== ($result['active_claims'] + $result['processing'] + $result['test_processing'] + $result['uncertain']) || 0 !== (int) $runtime['accepting_work']) {
                    throw new RuntimeException();
                }
                foreach ($saved['campaigns'] as $campaign) {
                    if ('sending' === $campaign['state'] && 0 < $campaign['remaining']) {
                        throw new RuntimeException();
                    }
                }
                $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=1,updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'");
            }
            return $result;
        });
        echo json_encode($inspection, JSON_THROW_ON_ERROR)."\n";
        exit(0);
    }
    if ('drain' === $input['action']) {
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0, updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'");
        $until = microtime(true) + 120;
        do {
            $active = $connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'");
            if (!is_numeric($active) || (int) $active < 0) {
                throw new RuntimeException();
            }
            $processing = (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_recipient WHERE state='processing'");
            $tests = (int) $connection->fetchOne("SELECT COUNT(*) FROM campaign_test_delivery WHERE state='processing'");
            if (0 === (int) $active && 0 === $processing && 0 === $tests) {
                echo "drained\n";
                exit(0);
            }
            usleep(250000);
        } while (microtime(true) < $until);
        throw new RuntimeException();
    }
    $name = $input['name'];
    $host = $input['host'];
    $database = $connection->getDatabase();
    if (!is_string($name) || !preg_match('/^em_[a-f0-9]{20}$/D', $name) || !is_string($host) || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || !is_string($database) || !preg_match('/^[A-Za-z0-9_]+$/D', $database)) {
        throw new RuntimeException();
    }
    $quoted = $connection->quote($name).'@'.$connection->quote($host);
    $count = (int) $connection->fetchOne('SELECT COUNT(*) FROM mysql.global_priv WHERE User=?', [$name]);
    if ('create' === $input['action'] && 0 === $count) {
        $connection->executeStatement('CREATE USER '.$quoted.' IDENTIFIED BY '.$connection->quote($input['password']).' ACCOUNT LOCK');
    } elseif (1 !== $count) {
        throw new RuntimeException();
    }
    $definition = $connection->fetchAssociative('SHOW CREATE USER '.$quoted);
    $hash = $connection->fetchOne('SELECT PASSWORD(?)', [$input['password']]);
    if (false === $definition || !is_string($hash) || !str_contains((string) reset($definition), $hash)) {
        throw new RuntimeException();
    }
    foreach ($connection->fetchFirstColumn('SHOW GRANTS FOR '.$quoted) as $grant) {
        if (!str_starts_with($grant, 'GRANT USAGE ON *.* TO ') && (!str_contains($grant, ' ON `'.$database.'`.* TO ') || str_contains($grant, 'WITH GRANT OPTION'))) {
            throw new RuntimeException();
        }
    }
    if ('create' === $input['action']) {
        $connection->executeStatement('GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER ON `'.$database.'`.* TO '.$quoted);
    } elseif ('verify' === $input['action']) {
        // Read-only replay verifies the operation-owned account before activation.
    } elseif ('probe' === $input['action']) {
        $parameters = (new \Doctrine\DBAL\Tools\DsnParser(['mysql' => 'pdo_mysql']))->parse($input['source_url']);
        $parameters['user'] = $name;
        $parameters['password'] = $input['password'];
        $parameters['host'] = $host;
        $application = \Doctrine\DBAL\DriverManager::getConnection($parameters);
        try {
            if ($application->getDatabase() !== $database || $application->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d') !== $connection->fetchAssociative('SELECT @@hostname h, @@port p, @@server_id i, @@socket s, @@datadir d')) {
                throw new RuntimeException();
            }
        } finally {
            $application->close();
        }
    } elseif (in_array($input['action'], ['lock', 'unlock'], true)) {
        $connection->executeStatement('ALTER USER '.$quoted.('lock' === $input['action'] ? ' ACCOUNT LOCK' : ' ACCOUNT UNLOCK'));
        if ('lock' === $input['action']) {
            foreach ($connection->fetchFirstColumn('SELECT ID FROM information_schema.PROCESSLIST WHERE USER=? AND ID<>CONNECTION_ID()', [$name]) as $id) {
                if (!is_numeric($id)) {
                    throw new RuntimeException();
                }
                $connection->executeStatement('KILL CONNECTION '.(int) $id);
            }
            $until = microtime(true) + 10;
            while (false !== $connection->fetchOne('SELECT 1 FROM information_schema.PROCESSLIST WHERE USER=? AND ID<>CONNECTION_ID()', [$name])) {
                if (microtime(true) >= $until) {
                    throw new RuntimeException();
                }
                usleep(100000);
            }
        }
    } else {
        throw new RuntimeException();
    }
    echo "verified\n";
} catch (Throwable $failure) {
    if ($failure instanceof EmailerUpdateDeliveryError) {
        echo json_encode(['installer_error' => 'delivery_state', 'message' => $failure->getMessage()], JSON_THROW_ON_ERROR)."\n";
    }
    if (isset($input['action']) && 'verify-endpoint' === $input['action']) {
        echo json_encode(['installer_error' => 'database_endpoint'], JSON_THROW_ON_ERROR)."\n";
    }
    fwrite(STDERR, "The dedicated database handoff could not be verified. Existing data and credentials were preserved.\n");
    exit(1);
}
