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

    /**
     * Assets belong to this installation's shared template library. Discovery
     * uses the same authority as editing templates, including earlier uploads.
     *
     * @return array{items: list<array{id: string, url: string, width: int, height: int, created_at: string, available: bool}>, next_cursor: ?string}
     */
    public function page(AdministrativeActor $actor, ?string $before = null): array
    {
        $this->authorization->assertAtLeastRank($actor, AdministrativeAccessLevel::Administrator, 'templates.asset.list');
        if (null !== $before && !self::identifier($before)) {
            throw new \InvalidArgumentException('Image page is invalid.');
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, filename, width, height, created_at FROM template_asset'.(null === $before ? '' : ' WHERE id < ?').' ORDER BY id DESC LIMIT 25',
            null === $before ? [] : [$before],
        );
        $items = [];
        foreach (\array_slice($rows, 0, 24) as $row) {
            $id = $row['id'];
            $filename = $row['filename'];
            $created = $row['created_at'];
            if (!\is_string($id) || !self::identifier($id) || !\is_string($filename)
                || 1 !== preg_match('/\A[a-f0-9]{48}\.(?:jpg|png|webp)\z/', $filename)
                || !\is_string($created)) {
                throw new \UnexpectedValueException('Stored image metadata is invalid.');
            }
            $available = true;
            try {
                $this->storage->publicPath($filename);
            } catch (\RuntimeException) {
                $available = false;
            }
            $items[] = ['id' => $id, 'url' => '/template-assets/'.$filename,
                'width' => self::dimension($row['width']), 'height' => self::dimension($row['height']),
                'created_at' => $created, 'available' => $available];
        }

        return ['items' => $items, 'next_cursor' => \count($rows) > 24 ? $items[23]['id'] : null];
    }

    private static function identifier(string $id): bool
    {
        return 1 === preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/', $id);
    }

    private static function dimension(mixed $value): int
    {
        if ((!\is_int($value) && !(\is_string($value) && ctype_digit($value))) || (int) $value < 1 || (int) $value > 2400) {
            throw new \UnexpectedValueException('Stored image dimensions are invalid.');
        }

        return (int) $value;
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
