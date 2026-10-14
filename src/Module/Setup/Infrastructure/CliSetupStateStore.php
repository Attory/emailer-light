<?php

declare(strict_types=1);

namespace App\Module\Setup\Infrastructure;

use App\Module\Setup\Application\Contract\CliSetupState;

final readonly class CliSetupStateStore implements CliSetupState
{
    public function __construct(private string $stateFile)
    {
    }

    public function recordOwnershipVerified(int $verifiedAt): void
    {
        $directory = \dirname($this->stateFile);
        FilesystemChallengeStore::ensurePrivateDirectory($directory);
        $contents = json_encode([
            'channel' => 'cli',
            'ownership_verified' => true,
            'verified_at' => $verifiedAt,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        if (false === @file_put_contents($this->stateFile, $contents, LOCK_EX) || !@chmod($this->stateFile, 0o600)) {
            @unlink($this->stateFile);

            throw new \RuntimeException('Unable to save CLI setup state safely.');
        }
    }

    public function delete(): void
    {
        if (file_exists($this->stateFile) && !@unlink($this->stateFile)) {
            throw new \RuntimeException('Unable to remove CLI setup state.');
        }
    }
}
