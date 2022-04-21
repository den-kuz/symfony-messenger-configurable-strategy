<?php

$finder = (new PhpCsFixer\Finder())->in(__DIR__.'/src');

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
        'blank_line_after_opening_tag' => false,
        'linebreak_after_opening_tag' => false,
        'phpdoc_summary' => false,
    ])
    ->setFinder($finder)
;
