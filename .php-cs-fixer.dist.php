<?php

declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__ . '/src')
    ->notPath('/docs/')
;

return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        '@PHP82Migration' => true,
        'align_multiline_comment' => [
            'comment_type' => 'phpdocs_only',
        ],
        'array_syntax' => [
            'syntax' => 'short',
        ],
        'binary_operator_spaces' => [
            'default' => 'single_space',
        ],
        'blank_line_after_namespace' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => [
                'return',
            ],
        ],
        'braces' => [
            'allow_single_line_closure' => false,
            'position_after_anonymous_constructs' => 'next',
            'position_after_control_structures' => 'next',
            'position_after_functions_and_oop_constructs' => 'next',
        ],
        'cast_spaces' => [
            'space' => 'none',
        ],
        'class_attributes_separation' => [
            'elements' => [
                'method' => 'one',
                'property' => 'one',
            ],
        ],
        'class_definition' => [
            'space_before_parenthesis' => false,
        ],
        'clean_namespace' => true,
        'combine_consecutive_issets' => true,
        'combine_consecutive_unsets' => true,
        'combine_nested_dirname' => true,
        'comment_to_phpdoc' => [
            'ignored_tags' => ['psalm', 'phpstan'],
        ],
        'constant_case' => [
            'case' => 'lower',
        ],
        'control_structure_braces' => [
            'brace_on_same_line' => true,
            'brace_on_same_line_regex' => '/^\\s*if\\(|for\\(|while\\(/',
        ],
        'control_structure_continuation_position' => [
            'position' => 'same_line',
        ],
        'date_time_immutable' => false,
        'declare_equal_normalize' => [
            'space' => 'none',
        ],
        'declare_strict_types' => true,
        'dir_constant' => true,
        'echo_tag_syntax' => [
            'format' => 'long',
            'short_echo_tag' => false,
        ],
        'empty_loop_body' => [
            'style' => 'braces',
        ],
        'empty_loop_condition' => [
            'style' => 'while',
        ],
        'ereg_to_preg' => true,
        'error_suppression' => [
            'noise_remaining_usages' => false,
            'remaining_usages' => false,
        ],
        'escape_implicit_backslashes' => [
            'double_quoted' => true,
            'heredoc' => true,
            'single_quoted' => false,
        ],
        'explicit_indirect_variable' => true,
        'explicit_string_variable' => true,
        'final_class' => false,
        'final_internal_class' => false,
        'final_public_method_for_abstract_class' => false,
        'fopen_flag_order' => true,
        'fopen_flags' => [
            'b_flag' => false,
        ],
        'full_opening_tag' => true,
        'function_declaration' => [
            'closure_function_spacing' => 'none',
        ],
        'function_to_constant' => [
            'functions' => [
                'get_called_class',
                'get_class',
                'get_class_called',
                'php_sapi_name',
                'php_uname',
            ],
        ],
        'function_typehint_space' => true,
        'general_phpdoc_annotation_remove' => [
            'annotations' => [
                'author',
            ],
        ],
        'general_phpdoc_tag_rename' => [
            'inline' => false,
            'replacements' => [
                'inheritdoc' => 'inheritDoc',
                'inheritdocs' => 'inheritDoc',
            ],
        ],
        'global_namespace_import' => [
            'import_classes' => false,
            'import_constants' => false,
            'import_functions' => false,
        ],
        'heredoc_indentation' => [
            'indentation' => 'same_as_start',
        ],
        'heredoc_to_nowdoc' => true,
        'implode_call' => true,
        'include' => true,
        'increment_style' => [
            'style' => 'pre',
        ],
        'index_array' => [
            'preserve_nulls' => false,
        ],
        'integer_literal_case' => true,
        'is_null' => true,
        'lambda_not_used_import' => true,
        'line_ending' => [
            'line_ending' => "\n",
        ],
        'linebreak_after_opening_tag' => true,
        'list_syntax' => [
            'syntax' => 'short',
        ],
        'lowercase_cast' => true,
        'lowercase_keywords' => true,
        'lowercase_static_reference' => true,
        'magic_constant_casing' => true,
        'magic_method_casing' => true,
        'method_argument_space' => [
            'on_multiline' => 'ignore',
        ],
        'method_chaining_indentation' => true,
        'modernize_types_casting' => true,
        'multiline_comment_opening_closing' => true,
        'multiline_whitespace_before_semicolons' => [
            'strategy' => 'no_multi_line',
        ],
        'native_function_casing' => true,
        'native_function_type_declaration_casing' => true,
        'new_with_braces' => true,
        'no_alias_functions' => true,
        'no_alias_language_construct_call' => true,
        'no_alternative_syntax' => true,
        'no_binary_string' => true,
        'no_blank_lines_after_class_opening' => true,
        'no_blank_lines_after_phpdoc' => true,
        'no_blank_lines_before_namespace' => true,
        'no_break_comment' => [
            'comment_text' => 'no break',
        ],
        'no_closing_tag' => true,
        'no_empty_comment' => true,
        'no_empty_phpdoc' => true,
        'no_empty_statement' => true,
        'no_extra_blank_lines' => [
            'tokens' => [
                'attribute',
                'break',
                'case',
                'continue',
                'curly_brace_block',
                'default',
                'extra',
                'parenthesis_brace_block',
                'return',
                'square_brace_block',
                'throw',
                'use',
            ],
        ],
        'no_extra_consecutive_blank_lines' => [
            'tokens' => [
                'break',
                'continue',
                'extra',
                'return',
                'throw',
                'use',
            ],
        ],
        'no_leading_import_slash' => true,
        'no_leading_namespace_whitespace' => true,
        'no_mixed_echo_print' => [
            'use' => 'echo',
        ],
        'no_multiline_whitespace_around_double_arrow' => true,
        'no_multiple_empty_lines' => true,
        'no_namespace_import_slash' => true,
        'no_null_property_initialization' => true,
        'no_short_bool_cast' => true,
        'no_singleline_whitespace_before_semicolons' => true,
        'no_space_around_double_colon' => true,
        'no_spaces_after_function_name' => true,
        'no_spaces_around_offset' => [
            'positions' => [
                'inside',
                'outside',
            ],
        ],
        'no_spaces_inside_parenthesis' => true,
        'no_superfluous_phpdoc_tags' => [
            'allow_mixed' => true,
            'allow_unused_params' => false,
        ],
        'no_trailing_comma_in_singleline' => true,
        'no_trailing_whitespace' => true,
        'no_trailing_whitespace_in_comment' => true,
        'no_unneeded_braces' => true,
        'no_unneeded_control_parentheses' => true,
        'no_unneeded_import_alias' => true,
        'no_unset_cast' => true,
        'no_unset_on_property' => true,
        'no_unused_imports' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_whitespace_before_comma_in_array' => true,
        'no_whitespace_in_blank_line' => true,
        'normalize_index_brace' => true,
        'not_operator_with_successor_not' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'object_operator_without_whitespace' => true,
        'operator_linebreak' => [
            'only_booleans' => true,
            'position' => 'end',
        ],
        'ordered_class_elements' => true,
        'ordered_imports' => [
            'imports_order' => [
                'const',
                'class',
                'function',
            ],
            'sort_algorithm' => 'alpha',
        ],
        'ordered_interfaces' => [
            'direction' => 'ascend',
            'order' => 'alpha',
        ],
        'ordered_traits' => true,
        'php_unit_construct' => [
            'assertions' => [
                'assertEquals',
                'assertEqualsCanonicalizing',
                'assertEqualsIgnoringCase',
                'assertNotEquals',
                'assertNotEqualsCanonicalizing',
                'assertNotEqualsIgnoringCase',
            ],
        ],
        'php_unit_dedicate_assert' => [
            'target' => 'newest',
        ],
        'php_unit_dedicate_assert_internal_type' => [
            'target' => 'newest',
        ],
        'php_unit_expectation' => [
            'target' => 'newest',
        ],
        'php_unit_fqname' => true,
        'php_unit_internal_class' => false,
        'php_unit_method_casing' => [
            'case' => 'camel_case',
        ],
        'php_unit_mock' => [
            'target' => 'newest',
        ],
        'php_unit_mock_short_will_return' => true,
        'php_unit_namespaced' => [
            'target' => 'newest',
        ],
        'php_unit_no_expectation_annotation' => [
            'target' => 'newest',
        ],
        'php_unit_set_up_tear_down_visibility' => true,
        'php_unit_strict' => [
            'target' => 'newest',
        ],
        'php_unit_test_annotation' => [
            'style' => 'prefix',
        ],
        'php_unit_test_case_static_method_calls' => [
            'call_type' => 'this',
        ],
        'php_unit_test_class_requires_covers' => false,
        'phpdoc_add_missing_param_annotation' => [
            'only_untyped' => false,
        ],
        'phpdoc_align' => [
            'align' => 'left',
        ],
        'phpdoc_annotation_without_dot' => true,
        'phpdoc_indent' => true,
        'phpdoc_inline_tag_normalizer' => true,
        'phpdoc_no_access' => true,
        'phpdoc_no_alias_tag' => [
            'replacements' => [
                'link' => 'see',
                'property-read' => 'property',
                'property-write' => 'property',
                'type' => 'var',
            ],
        ],
        'phpdoc_no_empty_return' => true,
        'phpdoc_no_package' => true,
        'phpdoc_no_useless_inheritdoc' => true,
        'phpdoc_order' => [
            'order' => [
                'param',
                'return',
                'throws',
            ],
        ],
        'phpdoc_return_self_reference' => true,
        'phpdoc_scalar' => true,
        'phpdoc_separation' => true,
        'phpdoc_single_line_var_spacing' => true,
        'phpdoc_summary' => true,
        'phpdoc_tag_casing' => [
            'tags' => [
                'inheritDoc',
            ],
        ],
        'phpdoc_tag_type' => [
            'tags' => [
                'inheritDoc' => 'inline',
            ],
        ],
        'phpdoc_to_comment' => [
            'ignored_tags' => ['psalm', 'phpstan'],
        ],
        'phpdoc_to_param_type' => false,
        'phpdoc_to_property_type' => false,
        'phpdoc_to_return_type' => false,
        'phpdoc_trim' => true,
        'phpdoc_trim_consecutive_blank_line_separation' => true,
        'phpdoc_types' => true,
        'phpdoc_types_order' => [
            'null_adjustment' => 'none',
            'sort_algorithm' => 'none',
        ],
        'phpdoc_var_annotation_correct_order' => true,
        'phpdoc_var_without_name' => true,
        'pow_to_exponentiation' => true,
        'protected_to_private' => true,
        'psr4' => true,
        'return_assignment' => false,
        'return_type_declaration' => [
            'space_before' => 'none',
        ],
        'self_accessor' => true,
        'self_static_accessor' => true,
        'semicolon_after_instruction' => true,
        'set_type_to_cast' => true,
        'short_scalar_cast' => true,
        'simple_variable_notation' => true,
        'simplified_null_return' => false,
        'single_blank_line_at_eof' => true,
        'single_class_element_per_statement' => true,
        'single_import_per_statement' => true,
        'single_line_after_imports' => true,
        'single_line_comment_style' => [
            'comment_types' => [
                'hash',
            ],
        ],
        'single_line_throw' => true,
        'single_quote' => true,
        'single_space_around_construct' => true,
        'space_after_semicolon' => [
            'remove_incomplete_statement' => false,
        ],
        'standardize_increment' => true,
        'standardize_not_equals' => true,
        'switch_case_semicolon_to_colon' => true,
        'switch_case_space' => true,
        'switch_continue_to_break' => true,
        'ternary_operator_spaces' => true,
        'ternary_to_elvis_operator' => true,
        'ternary_to_null_coalescing' => true,
        'trailing_comma_in_multiline' => [
            'after_heredoc' => false,
            'elements' => [
                'arrays',
            ],
        ],
        'trim_array_spaces' => true,
        'unary_operator_spaces' => true,
        'visibility_required' => [
            'elements' => [
                'method',
                'property',
            ],
        ],
        'void_return' => true,
        'whitespace_after_comma_in_array' => true,
        'yoda_style' => [
            'always_move_negative' => false,
            'equal' => null,
            'identical' => null,
            'less_and_greater' => null,
        ],
    ])
    ->setFinder($finder)
;
