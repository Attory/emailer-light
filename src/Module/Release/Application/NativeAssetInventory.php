<?php

declare(strict_types=1);

namespace App\Module\Release\Application;

/** An untouched installation has no uploaded template directory yet. */
final class NativeAssetInventory
{
    /** @param array<string, string> $roots */
    public static function capture(array $roots, string $privateDirectory): string
    {
        ksort($roots);
        $files = [];
        foreach ($roots as $name => $root) {
            if (!file_exists($root) && !is_link($root) && 'template-assets' === $name && $root === $privateDirectory.'/template-assets') {
                $parent = lstat($privateDirectory);
                if (!\is_array($parent) || !is_dir($privateDirectory) || realpath($privateDirectory) !== $privateDirectory || 0 !== ($parent['mode'] & 0o077)) {
                    throw new \RuntimeException('The empty template asset parent is not protected.');
                }
                $files[$name] = ['absent'];
                continue;
            }
            if (is_link($root) || !is_dir($root) || realpath($root) !== $root) {
                throw new \RuntimeException('An asset root is missing or linked.');
            }
            $paths = [$root];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->isLink() || (!$entry->isFile() && !$entry->isDir())) {
                    throw new \RuntimeException('An asset path is unsafe.');
                }
                $paths[] = $entry->getPathname();
            }
            sort($paths, SORT_STRING);
            foreach ($paths as $path) {
                $stat = lstat($path);
                if (!\is_array($stat)) {
                    throw new \RuntimeException('Asset metadata is unavailable.');
                }
                $files[$name.substr($path, \strlen($root))] = [$stat['uid'], $stat['gid'], $stat['mode'], is_file($path) ? hash_file('sha256', $path) : 'directory'];
            }
        }

        return hash('sha256', serialize($files));
    }
}
