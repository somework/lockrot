<?php

declare(strict_types=1);

$paths = array_filter(
    [__DIR__.'/src', __DIR__.'/tests', __DIR__.'/bin'],
    'is_dir'
);

// bin/lockrot and bin/record-fixtures are extensionless PHP scripts; Finder only picks up *.php by
// default, so they are named explicitly.
$finder = PhpCsFixer\Finder::create()
    ->in($paths)
    ->name('lockrot')
    ->name('record-fixtures');

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP74Migration' => true,
        'declare_strict_types' => true,
        'strict_comparison' => true,
        'no_unused_imports' => true,
        'ordered_imports' => true,
        'final_class' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        // allow_mixed keeps `@param mixed`: PHP 7.4 has no native `mixed` type.
        'no_superfluous_phpdoc_tags' => ['allow_mixed' => true],
        'phpdoc_no_empty_return' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
    ])
    ->setFinder($finder);
