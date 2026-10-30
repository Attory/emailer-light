<?php

declare(strict_types=1);

namespace App\Module\Delivery\Infrastructure;

use App\Module\Delivery\Application\Contract\DeliveryControl;
use App\Shared\DeliveryControlFiles;
use Doctrine\DBAL\Connection;

final readonly class FilesystemDeliveryControl implements DeliveryControl
{
    public function __construct(private Connection $connection, private string $privateDirectory)
    {
    }

    public function status(): array
    {
        return $this->files()->status();
    }

    public function pause(): bool
    {
        try {
            $files = $this->files();
        } catch (\Doctrine\DBAL\Exception $error) {
            // During the updater's account fence only, use the local installation
            // record to persist a pause. Normal reads and Resume bind to the DB.
            $path = $this->privateDirectory.'/install.lock';
            $stat = @lstat($path);
            if (!\is_array($stat) || 0o100000 !== ($stat['mode'] & 0o170000) || 1 !== $stat['nlink'] || 0 !== ($stat['mode'] & 0o077) || 4096 < $stat['size']) {
                throw $error;
            }
            $bytes = @file_get_contents($path);
            $metadata = false === $bytes ? null : json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            if (!\is_array($metadata) || !\is_string($metadata['installation_uuid'] ?? null)) {
                throw $error;
            }
            $files = new DeliveryControlFiles($this->privateDirectory, $metadata['installation_uuid']);
        }
        if ($files->status()['configured']) {
            $files->pause();

            return true;
        }

        return false;
    }

    public function claimLease(): \App\Shared\DeliveryIntentLease
    {
        return $this->files()->claimLease();
    }

    public function enable(): void
    {
        $files = $this->files();
        if ($files->status()['configured']) {
            $files->enable();
        }
    }

    private function files(): DeliveryControlFiles
    {
        $installation = $this->connection->fetchOne("SELECT installation_uuid FROM installation_state WHERE singleton_key='1'");
        if (!\is_string($installation)) {
            throw new \RuntimeException('Delivery control installation identity is unavailable.');
        }

        return new DeliveryControlFiles($this->privateDirectory, $installation);
    }
}
