<?php

declare(strict_types=1);

/*
 * Normes de codage du projet TNB.
 *
 * Le jeu de regles "@Symfony" inclut "@PSR12", exige par le CDCF 3.4
 * ("Qualite de code : respect des normes PSR-12").
 */

$finder = (new PhpCsFixer\Finder())
    ->in([
        __DIR__.'/config',
        __DIR__.'/migrations',
        __DIR__.'/public',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->notPath('bundles.php')
    ->append([__FILE__])
;

return (new PhpCsFixer\Config())
    // Le projet cible PHP 8.2 ; l'outil tourne sur une version plus recente
    // en local comme en CI, ce qui est sans consequence sur les regles
    // utilisees ici.
    ->setUnsupportedPhpVersionAllowed(true)
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@PSR12' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,
        'phpdoc_align' => ['align' => 'left'],
        'yoda_style' => true,
    ])
    ->setFinder($finder)
;
