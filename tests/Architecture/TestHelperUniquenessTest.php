<?php

declare(strict_types=1);

/*
 * Guards against duplicate global helper functions across the test suite.
 *
 * Pest declares a helper written in a test file as a plain global function.
 * Two files declaring the same name is a fatal "Cannot redeclare", raised
 * while PHPUnit is still loading files — so it kills the whole suite before
 * a single test runs, and each file still passes on its own.
 *
 * That has now happened twice: `authPerfAs()` in two performance suites, and
 * `poster()` in the journal and invoice posting suites. Both times whole-
 * suite runs died with no output while every targeted run stayed green,
 * which is the worst possible failure shape — it looks like the runner is
 * broken rather than the tests.
 *
 * A genuinely shared helper belongs in tests/Pest.php, which is loaded once.
 */

it('declares each global test helper exactly once', function () {
    // dirname(), not base_path(): the Architecture suite runs without a
    // booted application, so the container helpers are unavailable here.
    $testsDir = dirname(__DIR__);
    $declarations = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testsDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        // Top-level declarations only — an indented `function` is a method
        // or a closure, and neither pollutes the global namespace.
        preg_match_all('/^function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m', $contents, $matches);

        foreach ($matches[1] as $name) {
            $declarations[$name][] = str_replace($testsDir.'/', '', $file->getPathname());
        }
    }

    $duplicates = array_filter($declarations, static fn (array $files): bool => count($files) > 1);

    $message = implode("\n", array_map(
        static fn (string $name, array $files): string => sprintf(
            '  %s() — declared in %s',
            $name,
            implode(' and ', $files),
        ),
        array_keys($duplicates),
        $duplicates,
    ));

    expect($duplicates)->toBe([], $message === ''
        ? ''
        : "These helpers are declared more than once, which fatals the whole suite at load time.\nMove a shared helper into tests/Pest.php, or rename one:\n".$message);
});
