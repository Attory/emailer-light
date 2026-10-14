<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\ChallengeCode;
use App\Module\Setup\Application\Contract\ProofFile;

final readonly class FilesystemProofFile implements ProofFile
{
    private const MAX_SIZE = 256;

    public function __construct(private string $proofFile)
    {
    }

    public function readNormalizedCode(): ?string
    {
        $pathMetadata = self::lstatOrNull($this->proofFile);

        if (null === $pathMetadata || 'file' !== @filetype($this->proofFile)) {
            return null;
        }

        if ($pathMetadata['size'] > self::MAX_SIZE || 0 !== ($pathMetadata['mode'] & 0o022)) {
            return null;
        }

        $handle = @fopen($this->proofFile, 'rb');

        if (false === $handle) {
            return null;
        }

        try {
            $openedMetadata = fstat($handle);

            if (
                false === $openedMetadata
                || 0o100000 !== ($openedMetadata['mode'] & 0o170000)
                || $openedMetadata['size'] > self::MAX_SIZE
                || 0 !== ($openedMetadata['mode'] & 0o022)
                || $openedMetadata['dev'] !== $pathMetadata['dev']
                || $openedMetadata['ino'] !== $pathMetadata['ino']
            ) {
                return null;
            }

            $contents = stream_get_contents($handle, self::MAX_SIZE + 1);

            if (false === $contents || \strlen($contents) > self::MAX_SIZE) {
                return null;
            }

            $afterReadMetadata = self::lstatOrNull($this->proofFile);

            if (
                null === $afterReadMetadata
                || $afterReadMetadata['dev'] !== $openedMetadata['dev']
                || $afterReadMetadata['ino'] !== $openedMetadata['ino']
                || 0 !== ($afterReadMetadata['mode'] & 0o022)
            ) {
                return null;
            }

            return ChallengeCode::normalize($contents);
        } finally {
            fclose($handle);
        }
    }

    public function delete(): void
    {
        if ((file_exists($this->proofFile) || is_link($this->proofFile)) && !@unlink($this->proofFile)) {
            throw new \RuntimeException('Unable to remove ownership proof.');
        }
    }

    /** @return array<string|int, int>|null */
    private static function lstatOrNull(string $path): ?array
    {
        $metadata = @lstat($path);

        return false === $metadata ? null : $metadata;
    }
}
