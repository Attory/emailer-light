<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** Root-controlled operation evidence; never stored inside application-writable private data. */
final class NativeUpdateJournal
{
    public static function integer(mixed $value): int
    {
        if ((!\is_int($value) && !\is_string($value)) || !is_numeric($value)) {
            throw new \RuntimeException('Native update numeric evidence is invalid.');
        }

        return (int) $value;
    }

    public static function text(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new \RuntimeException('Native update evidence is incomplete.');
        }

        return $value;
    }

    public static function regular(string $path, bool $public = false): void
    {
        $s = @lstat($path);
        if (!\is_array($s) || 0o100000 !== ($s['mode'] & 0o170000) || 1 !== $s['nlink'] || 0 !== ($s['mode'] & ($public ? 0o022 : 0o027)) || is_link($path)) {
            throw new \RuntimeException('Native update material is missing or has unsafe permissions.');
        }
        self::ancestors($path);
    }

    private static function ancestors(string $path): void
    {
        if (!str_starts_with($path, '/') || str_contains($path, '/..') || str_contains($path, '//')) {
            throw new \RuntimeException('Native update paths must be normalized and absolute.');
        }
        for ($p = \dirname($path); '/' !== $p; $p = \dirname($p)) {
            if (is_link($p)) {
                throw new \RuntimeException('Native update paths cannot have symbolic-link ancestors.');
            }
        }
    }

    public static function directory(string $path): void
    {
        self::ancestors($path.'/evidence');
        for ($parent = \dirname($path); '/' !== $parent; $parent = \dirname($parent)) {
            $s = @lstat($parent);
            if (!\is_array($s) || 0 !== $s['uid'] || (0 !== ($s['mode'] & 0o022) && 0 === ($s['mode'] & 0o1000))) {
                throw new \RuntimeException('Native recovery storage must have root-controlled ancestors.');
            }
        }
        if (!is_dir($path) && !mkdir($path, 0o700, true)) {
            throw new \RuntimeException('Native recovery directory cannot be created.');
        }
        $s = lstat($path);
        if (!\is_array($s) || 0o040000 !== ($s['mode'] & 0o170000) || 0 !== $s['uid'] || 0 !== ($s['mode'] & 0o077)) {
            throw new \RuntimeException('Native recovery directory must be private and root-owned.');
        }
    }

    /** @return array<string, mixed> */
    public static function read(string $path, bool $public = false): array
    {
        self::regular($path, $public);
        $s = lstat($path);
        if (!\is_array($s) || 0 !== $s['uid'] || $s['size'] > 1048576) {
            throw new \RuntimeException('Native recovery journal custody is unsafe.');
        }
        $value = json_decode(self::text(file_get_contents($path)), true, 32, JSON_THROW_ON_ERROR);
        if (!\is_array($value) || !array_all(array_keys($value), static fn (mixed $key): bool => \is_string($key))) {
            throw new \RuntimeException('Native recovery journal format is invalid.');
        }

        $result = [];
        foreach ($value as $key => $entry) {
            if (!\is_string($key)) {
                throw new \RuntimeException('Native recovery journal keys are invalid.');
            }
            $result[$key] = $entry;
        }

        return $result;
    }

    public static function write(string $path, string $bytes): void
    {
        self::directory(\dirname($path));
        if (file_exists($path)) {
            self::regular($path);
        }
        $temporary = \dirname($path).'/.write-'.bin2hex(random_bytes(12));
        $handle = fopen($temporary, 'xb');
        if (false === $handle) {
            throw new \RuntimeException('Native recovery evidence cannot be staged.');
        }
        try {
            if (!chmod($temporary, 0o600) || \strlen($bytes) !== fwrite($handle, $bytes) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Native recovery evidence cannot be synchronized.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Native recovery evidence cannot be published.');
        }
        $directory = fopen(\dirname($path), 'rb');
        if (false === $directory) {
            throw new \RuntimeException('Native recovery directory cannot be opened.');
        }
        try {
            if (!fsync($directory)) {
                throw new \RuntimeException('Native recovery directory cannot be synchronized.');
            }
        } finally {
            fclose($directory);
        }
    }
}
