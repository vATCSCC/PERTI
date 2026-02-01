<?php
/**
 * PHP-CS-Fixer configuration for PERTI
 *
 * Install: composer require --dev friendsofphp/php-cs-fixer
 * Run: vendor/bin/php-cs-fixer fix
 * Check only: vendor/bin/php-cs-fixer fix --dry-run --diff
 */

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('node_modules')
    ->exclude('storage')
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        // PSR-12 compliance
        '@PSR12' => true,

        // Array syntax
        'array_syntax' => ['syntax' => 'short'],
        'no_whitespace_before_comma_in_array' => true,
        'whitespace_after_comma_in_array' => true,

        // Braces and spacing
        'braces' => true,
        'no_spaces_around_offset' => true,
        'no_trailing_whitespace' => true,
        'no_whitespace_in_blank_line' => true,

        // Function calls
        'function_typehint_space' => true,
        'no_spaces_after_function_name' => true,
        'single_line_throw' => false,

        // Imports
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'no_unused_imports' => true,

        // Operators
        'binary_operator_spaces' => true,
        'concat_space' => ['spacing' => 'one'],
        'not_operator_with_successor_space' => false,

        // Strings
        'single_quote' => true,

        // Semicolons
        'no_empty_statement' => true,
        'semicolon_after_instruction' => true,

        // Comments
        'no_empty_comment' => true,
        'single_line_comment_style' => ['comment_types' => ['hash']],

        // Control structures
        'elseif' => true,
        'include' => true,
        'no_alternative_syntax' => false, // Allow short syntax in templates

        // Strict types (warning - may need gradual rollout)
        // 'declare_strict_types' => true,

        // Class notation
        'class_attributes_separation' => [
            'elements' => ['method' => 'one', 'property' => 'one'],
        ],
        'no_blank_lines_after_class_opening' => true,
        'ordered_class_elements' => [
            'order' => ['use_trait', 'constant', 'property', 'construct', 'method'],
        ],
        'self_accessor' => true,
        'visibility_required' => ['elements' => ['property', 'method', 'const']],

        // PHP version features
        'array_push' => true, // Use $arr[] = $val instead of array_push
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'is_null' => true, // Use === null instead of is_null()
        'modernize_types_casting' => true,
        'no_alias_functions' => true,
        'nullable_type_declaration_for_default_null_value' => true,

        // PHPDoc
        'no_empty_phpdoc' => true,
        'no_superfluous_phpdoc_tags' => false,
        'phpdoc_align' => ['align' => 'left'],
        'phpdoc_indent' => true,
        'phpdoc_scalar' => true,
        'phpdoc_summary' => true,
        'phpdoc_trim' => true,
        'phpdoc_types' => true,
    ])
    ->setFinder($finder)
    ->setRiskyAllowed(true)
    ->setUsingCache(true)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
