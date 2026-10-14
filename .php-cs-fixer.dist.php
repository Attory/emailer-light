<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/bin',
        __DIR__.'/config',
        __DIR__.'/migrations',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/tools',
    ])
    ->notPath([
        'config/bundles.php',
    ])
    ->notName('reference.php')
    ->append([
        __DIR__.'/importmap.php',
    ])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => [
            'include' => ['@compiler_optimized'],
            'scope' => 'namespaced',
            'strict' => true,
        ],
    ])
    ->setFinder($finder)
;
