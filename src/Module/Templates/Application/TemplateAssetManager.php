<?php

declare(strict_types=1);

namespace App\Module\Templates\Application;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\Contract\AdministrativeAccessLevel;
use App\Module\Identity\Application\Contract\AdministrativeActor;
use App\Module\Identity\Application\Contract\AdministrativeAuthorization;
use App\Module\Templates\Application\Contract\TemplateAssetStorage;
use App\Shared\Identity;
use Doctrine\DBAL\Connection;

final readonly class TemplateAssetManager
{
    public function __construct(private Connection $connection, private AdministrativeAuthorization $authorization, private AuditSink $audit, private TemplateAssetStorage $storage)
    {
    }

    /** @return array{id: string, url: string, width: int, height: int} */
    public function store(AdministrativeActor $actor, string $sourcePath): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'templates.asset.upload');
        $asset = $this->storage->store($sourcePath);
        $id = Identity::uuidV7();
        try {
            $this->connection->transactional(function () use ($actor, $asset, $id): void {
                $this->connection->insert('template_asset', ['id' => $id, 'filename' => $asset['filename'], 'media_type' => $asset['media_type'], 'byte_size' => $asset['byte_size'], 'width' => $asset['width'], 'height' => $asset['height'], 'content_sha256' => $asset['content_sha256'], 'created_by' => $actor->id(), 'created_at' => Identity::now()]);
                $this->audit->append(new AuditRecord($actor->id(), 'template.asset.uploaded', $id, 'success', ['media_type' => $asset['media_type'], 'byte_size' => $asset['byte_size'], 'width' => $asset['width'], 'height' => $asset['height']]));
            });
        } catch (\Throwable $exception) {
            $this->storage->delete($asset['filename']);
            throw $exception;
        }

        return ['id' => $id, 'url' => $asset['url'], 'width' => $asset['width'], 'height' => $asset['height']];
    }
}
