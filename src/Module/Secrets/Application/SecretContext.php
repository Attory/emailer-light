<?php

declare(strict_types=1);

namespace App\Module\Secrets\Application;

final readonly class SecretContext
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public string $installationId,
        public string $recordType,
        public string $recordId,
        public int $schemaVersion = self::SCHEMA_VERSION,
    ) {
        if ('' === $installationId || '' === $recordType || '' === $recordId || 1 > $schemaVersion) {
            throw new \InvalidArgumentException('Secret context is invalid.');
        }
    }

    public function associatedData(): string
    {
        return json_encode([
            'installation_id' => $this->installationId,
            'record_type' => $this->recordType,
            'record_id' => $this->recordId,
            'schema_version' => $this->schemaVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
