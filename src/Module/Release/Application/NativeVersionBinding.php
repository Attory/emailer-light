<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Canonical metadata is bound to one authenticated operation, never the release train. */
final readonly class NativeVersionBinding
{
    public function __construct(public string $source, public string $target)
    {
        if (!NativeSchemaUpdate::supports($source, $target)) {
            throw new \RuntimeException('The native metadata release pair is unsupported.');
        }
    }

    public function canonical(mixed $version): string
    {
        if (!\in_array($version, [$this->source, $this->target], true)) {
            throw new \RuntimeException('Installation metadata has drifted outside the authenticated update pair.');
        }

        return $this->source;
    }

    public function schema(mixed $schema): string
    {
        if ('1.2.6' !== $this->target || !\in_array($schema, [CampaignDraftSchemaPlan::SOURCE_SCHEMA, CampaignDraftSchemaPlan::TARGET_SCHEMA], true)) {
            throw new \RuntimeException('Installation schema metadata is outside the authenticated draft-migration pair.');
        }

        return CampaignDraftSchemaPlan::SOURCE_SCHEMA;
    }

    public function lock(string $bytes, bool $sourceOnly = false): string
    {
        $value = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || ($sourceOnly && ($value['product_version'] ?? null) !== $this->source)) {
            throw new \RuntimeException('The source installation lock version is inconsistent.');
        }
        $recordedVersion = $value['product_version'] ?? null;
        if ('1.2.6' === $this->target && ($value['schema_version'] ?? null) !== ($recordedVersion === $this->target ? CampaignDraftSchemaPlan::TARGET_SCHEMA : CampaignDraftSchemaPlan::SOURCE_SCHEMA)) {
            throw new \RuntimeException('The installation lock version and schema are not a permitted pair.');
        }
        $value['product_version'] = $this->canonical($value['product_version'] ?? null);
        if ('1.2.6' === $this->target) {
            if ($sourceOnly && CampaignDraftSchemaPlan::SOURCE_SCHEMA !== ($value['schema_version'] ?? null)) {
                throw new \RuntimeException('The source installation lock schema does not match the authenticated source.');
            }
            $value['schema_version'] = $this->schema($value['schema_version'] ?? null);
        }

        return json_encode($value, JSON_THROW_ON_ERROR);
    }
}
