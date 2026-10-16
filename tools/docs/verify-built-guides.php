<?php

declare(strict_types=1);

// Release-only check: rendered guides must come from this exact clean source.
if (3 !== $argc || 1 !== preg_match('/^[a-f0-9]{40}$/D', $argv[2])) {
    throw new RuntimeException('Expected documentation directory and exact source commit.');
}
$root = dirname(__DIR__, 2);
$directory = realpath($argv[1]);
if (false === $directory) {
    throw new RuntimeException('Build the reviewed documentation before packaging the release.');
}
$manifest = json_decode((string) file_get_contents($directory.'/DOCS-MANIFEST.json'), true, 64, JSON_THROW_ON_ERROR);
if ('emailer-docs-v1' !== ($manifest['format'] ?? null) || $argv[2] !== ($manifest['source_commit'] ?? null)) {
    throw new RuntimeException('Documentation was not built from the exact release commit.');
}
$expected = ['composer.json', 'tools/docs/build-guides.py', 'tools/docs/check-guides.py', 'tools/docs/requirements.txt', 'tools/docs/verify-built-guides.php', 'docs/guides/FONT-LICENSE.txt'];
foreach (['docs/guides/*.json', 'docs/guides/*.md', 'docs/guides/images/*.png'] as $pattern) {
    foreach (glob($root.'/'.$pattern) ?: [] as $file) {
        $expected[] = substr($file, strlen($root) + 1);
    }
}
sort($expected);
$actual = array_keys($manifest['inputs'] ?? []);
sort($actual);
if ($actual !== $expected) {
    throw new RuntimeException('Documentation input inventory is incomplete.');
}
foreach ($manifest['inputs'] as $relative => $digest) {
    if (!is_string($digest) || 1 !== preg_match('/^[a-f0-9]{64}$/D', $digest) || !hash_equals($digest, (string) hash_file('sha256', $root.'/'.$relative))) {
        throw new RuntimeException('Documentation source changed after rendering.');
    }
}
$outputs = ['FONT-LICENSE.txt'];
foreach (glob($root.'/docs/guides/*.json') ?: [] as $source) {
    foreach (['html', 'pdf'] as $extension) {
        $outputs[] = basename($source, '.json').'.'.$extension;
    }
}
sort($outputs);
$actual = array_keys($manifest['outputs'] ?? []);
sort($actual);
if ($actual !== $outputs) {
    throw new RuntimeException('Documentation output inventory is incomplete.');
}
$directoryFiles = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
$completeOutputs = [...$outputs, 'DOCS-MANIFEST.json'];
sort($directoryFiles);
sort($completeOutputs);
if ($directoryFiles !== $completeOutputs) {
    throw new RuntimeException('Unexpected file in documentation output; release packaging stopped.');
}
foreach ($manifest['outputs'] as $name => $digest) {
    if (!is_string($digest) || is_link($directory.'/'.$name) || !is_file($directory.'/'.$name) || !hash_equals($digest, (string) hash_file('sha256', $directory.'/'.$name))) {
        throw new RuntimeException('A rendered documentation file failed verification.');
    }
}
echo "Exact-source documentation and complete rendered inventory verified.\n";
