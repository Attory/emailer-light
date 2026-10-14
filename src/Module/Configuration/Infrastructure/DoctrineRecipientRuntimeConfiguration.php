<?php

declare(strict_types=1);

namespace App\Module\Configuration\Infrastructure;

use App\Module\Configuration\Application\Contract\RecipientRuntimeConfiguration;
use Doctrine\DBAL\Connection;

final readonly class DoctrineRecipientRuntimeConfiguration implements RecipientRuntimeConfiguration
{
    public function __construct(private Connection $connection)
    {
    }

    public function current(): array
    {
        $row = $this->connection->fetchAssociative("SELECT installation_uuid, canonical_base_url FROM organisation_settings WHERE singleton_key = '1'");
        if (false === $row || !\is_string($row['installation_uuid']) || !\is_string($row['canonical_base_url'])) {
            throw new \RuntimeException('Recipient runtime configuration is unavailable.');
        }

        $parts = parse_url($row['canonical_base_url']);
        if (false === $parts || 'https' !== ($parts['scheme'] ?? null) || !\is_string($parts['host'] ?? null) || '' === $parts['host']
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && !\in_array($parts['path'], ['', '/'], true))
            || 1 === preg_match('/[\x00-\x20\x7F]/', $row['canonical_base_url'])
        ) {
            throw new \RuntimeException('Recipient runtime configuration is unavailable.');
        }

        $port = isset($parts['port']) && 443 !== $parts['port'] ? ':'.$parts['port'] : '';

        return ['installation_id' => $row['installation_uuid'], 'canonical_origin' => 'https://'.strtolower($parts['host']).$port];
    }
}
