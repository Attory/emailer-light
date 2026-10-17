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
    if ('drain' === $input['action']) {
        $connection->executeStatement("UPDATE delivery_runtime SET accepting_work=0, updated_at=UTC_TIMESTAMP(6) WHERE singleton_key='1'");
        $until = microtime(true) + 120;
        do {
            $active = $connection->fetchOne("SELECT active_claims FROM delivery_runtime WHERE singleton_key='1'");
            if (!is_numeric($active) || (int) $active < 0) {
                throw new RuntimeException();
            }
            if (0 === (int) $active) {
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
} catch (Throwable) {
    if (isset($input['action']) && 'verify-endpoint' === $input['action']) {
        echo json_encode(['installer_error' => 'database_endpoint'], JSON_THROW_ON_ERROR)."\n";
    }
    fwrite(STDERR, "The dedicated database handoff could not be verified. Existing data and credentials were preserved.\n");
    exit(1);
}
