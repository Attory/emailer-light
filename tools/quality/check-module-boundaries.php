<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

$sourceRoot = dirname(__DIR__, 2).'/src';
$violations = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (!$file->isFile() || 'php' !== $file->getExtension()) {
        continue;
    }

    $contents = file_get_contents($file->getPathname());
    if (false === $contents) {
        throw new RuntimeException('Unable to inspect a source file.');
    }

    $relativePath = substr($file->getPathname(), strlen($sourceRoot) + 1);

    if (1 === preg_match('/\btenant(?:_id|Id|-id)\b/i', $contents)) {
        $violations[] = $relativePath.': tenant discriminator is prohibited';
    }

    if (1 !== preg_match('/namespace\s+([^;]+);/', $contents, $namespaceMatch)) {
        continue;
    }

    $namespace = trim($namespaceMatch[1]);
    if (
        1 === preg_match('/^App\\\\Module\\\\([^\\\\]+)\\\\([^\\\\]+)/', $namespace, $sourceMatch)
        && 1 === preg_match_all('/^use\s+([^;]+);/m', $contents, $useMatches)
    ) {
        $sourceModule = $sourceMatch[1];
        $sourceLayer = $sourceMatch[2];

        foreach ($useMatches[1] as $import) {
            $import = trim($import);

            if (
                1 === preg_match('/^App\\\\Module\\\\([^\\\\]+)\\\\(.+)$/', $import, $targetMatch)
                && $sourceModule !== $targetMatch[1]
                && !str_starts_with($targetMatch[2], 'Application\\Contract\\')
            ) {
                $violations[] = $relativePath.': cross-module import bypasses Application\\Contract';
            }

            if (
                'Domain' === $sourceLayer
                && 1 === preg_match('/^(?:Doctrine|Monolog|Psr|Symfony|Twig)\\\\/', $import)
            ) {
                $violations[] = $relativePath.': Domain imports infrastructure/framework code';
            }
        }
    }

    if (
        str_ends_with($relativePath, 'Controller.php')
        && 1 !== preg_match('/^App\\\\Module\\\\[^\\\\]+\\\\Presentation\\\\Http(?:\\\\|$)/', $namespace)
    ) {
        $violations[] = $relativePath.': HTTP controller is outside a module Presentation layer';
    }
}

if ([] !== $violations) {
    fwrite(STDERR, implode(PHP_EOL, $violations).PHP_EOL);
    exit(1);
}
