<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\ChallengeStore;
use App\Module\Setup\Domain\ChallengeState;

final readonly class FilesystemChallengeStore implements ChallengeStore
{
    public function __construct(private string $stateFile)
    {
    }

    public function load(): ?ChallengeState
    {
        $metadata = self::lstatOrNull($this->stateFile);

        if (null === $metadata) {
            return null;
        }

        if ('file' !== filetype($this->stateFile) || $metadata['size'] > 4096 || 0 !== ($metadata['mode'] & 0o022)) {
            throw new \RuntimeException('Setup state has unsafe filesystem metadata.');
        }

        $handle = @fopen($this->stateFile, 'rb');

        if (false === $handle) {
            throw new \RuntimeException('Setup state cannot be read safely.');
        }

        try {
            $openedMetadata = fstat($handle);

            if (
                false === $openedMetadata
                || 0o100000 !== ($openedMetadata['mode'] & 0o170000)
                || $openedMetadata['size'] > 4096
                || 0 !== ($openedMetadata['mode'] & 0o022)
                || $openedMetadata['dev'] !== $metadata['dev']
                || $openedMetadata['ino'] !== $metadata['ino']
            ) {
                throw new \RuntimeException('Setup state changed during validation.');
            }

            $contents = stream_get_contents($handle, 4097);
            $afterReadMetadata = self::lstatOrNull($this->stateFile);

            if (
                false === $contents
                || \strlen($contents) > 4096
                || null === $afterReadMetadata
                || $afterReadMetadata['dev'] !== $openedMetadata['dev']
                || $afterReadMetadata['ino'] !== $openedMetadata['ino']
                || 0 !== ($afterReadMetadata['mode'] & 0o022)
            ) {
                throw new \RuntimeException('Setup state changed during validation.');
            }
        } finally {
            fclose($handle);
        }

        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Setup state is malformed.', 0, $exception);
        }

        if (!\is_array($data)) {
            throw new \RuntimeException('Setup state is malformed.');
        }

        return ChallengeState::fromArray($data);
    }

    public function save(ChallengeState $state): void
    {
        $directory = \dirname($this->stateFile);
        self::ensurePrivateDirectory($directory);
        $temporaryFile = $directory.'/.challenge-'.bin2hex(random_bytes(8)).'.tmp';
        $json = json_encode($state->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        try {
            if (false === @file_put_contents($temporaryFile, $json, LOCK_EX)) {
                throw new \RuntimeException('Unable to write setup state.');
            }

            if (!@chmod($temporaryFile, 0o600)) {
                throw new \RuntimeException('Unable to protect setup state.');
            }

            if (!@rename($temporaryFile, $this->stateFile)) {
                throw new \RuntimeException('Unable to publish setup state.');
            }
        } finally {
            if (is_file($temporaryFile)) {
                @unlink($temporaryFile);
            }
        }
    }

    public function delete(): void
    {
        if (file_exists($this->stateFile) && !@unlink($this->stateFile)) {
            throw new \RuntimeException('Unable to remove setup state.');
        }
    }

    public static function ensurePrivateDirectory(string $directory): void
    {
        if (!is_dir($directory) && !@mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create private setup storage.');
        }

        $metadata = @lstat($directory);

        if (false === $metadata || 0o040000 !== ($metadata['mode'] & 0o170000)) {
            throw new \RuntimeException('Private setup storage is not a regular directory.');
        }

        if (!@chmod($directory, 0o700)) {
            throw new \RuntimeException('Unable to protect private setup storage.');
        }
    }

    /** @return array<string|int, int>|null */
    private static function lstatOrNull(string $path): ?array
    {
        $metadata = @lstat($path);

        return false === $metadata ? null : $metadata;
    }
}
