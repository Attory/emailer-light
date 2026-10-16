<?php

declare(strict_types=1);

namespace App\Shared;

/** Installer-owned hold outside every application-writable directory. Unsafe evidence fails closed. */
final readonly class InstallerDeliveryHold
{
    public function __construct(private string $holdFile = '')
    {
    }

    public function active(): bool
    {
        return '' !== $this->holdFile && (!$this->safeParents() || file_exists($this->holdFile) || is_link($this->holdFile));
    }

    public function permitsOfflineVerification(string $installation): bool
    {
        if ('' === $this->holdFile || !$this->safeParents()) {
            return false;
        }
        $metadata = @lstat($this->holdFile);
        if (!\is_array($metadata) || 0o100000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022) || 1 !== $metadata['nlink']) {
            return false;
        }
        $bytes = @file_get_contents($this->holdFile);
        if (false === $bytes || 4096 < \strlen($bytes)) {
            return false;
        }
        $value = json_decode($bytes, true);

        return \is_array($value) && 'emailer-installer-hold-v1' === ($value['format'] ?? null) && $installation === ($value['installation_uuid'] ?? null) && 'host-handoff' === ($value['phase'] ?? null);
    }

    private function safeParents(): bool
    {
        if (!str_starts_with($this->holdFile, '/') || 1 === preg_match('~(^|/)\.\.?(/|$)|//~', $this->holdFile)) {
            return false;
        }
        for ($parent = \dirname($this->holdFile);; $parent = \dirname($parent)) {
            $metadata = @lstat($parent);
            if (!\is_array($metadata) || 0o040000 !== ($metadata['mode'] & 0o170000) || 0 !== $metadata['uid'] || 0 !== ($metadata['mode'] & 0o022)) {
                return false;
            }
            if ('/' === $parent) {
                return true;
            }
        }
    }
}
