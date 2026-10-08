<?php

declare(strict_types=1);

/**
 * IDE stubs for the winter_boot native extension.
 *
 * This file is never loaded at runtime: the real functions are provided by
 * the extension itself (required since Winter Boot 2.1.0 — the application
 * stops at boot when it is missing). It exists so that IDEs and static
 * analysers do not report the native functions as undefined. The guard
 * below keeps a stray include from redeclaring them when the extension is
 * present.
 */

if (!extension_loaded('winter_boot')) {
    /**
     * Register a class method for native AOP interception.
     *
     * @param class-string $class bean class owning the method
     * @throws \Error for unknown, abstract, constructor, or destructor methods
     */
    function winter_boot_advise(string $class, string $method): void {
        throw new \LogicException('winter_boot extension is not loaded');
    }

    /**
     * Whether the given class method is registered for native interception.
     *
     * @param class-string $class bean class owning the method
     */
    function winter_boot_is_advised(string $class, string $method): bool {
        throw new \LogicException('winter_boot extension is not loaded');
    }

    /**
     * Evaluate framework `#{...}` template code with variables bound.
     * Repeat evaluations reuse a cached compilation with identical results.
     *
     * @param array<string, mixed> $vars variables visible to the code
     * @throws \Error without a userland caller scope
     */
    function winter_boot_exec_inline(string $code, array $vars): mixed {
        throw new \LogicException('winter_boot extension is not loaded');
    }

    /**
     * Substitute literal placeholder text with evaluated values, in pair
     * order (str_replace semantics: all occurrences per pair, sequential
     * passes, same value coercion).
     *
     * @param array<string, mixed> $pairs placeholder text => evaluated value
     */
    function winter_boot_expand_template(string $template, array $pairs): string {
        throw new \LogicException('winter_boot extension is not loaded');
    }
}
