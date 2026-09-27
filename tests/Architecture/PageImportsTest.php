<?php

declare(strict_types=1);

/*
 * Guards against a page importing from another page.
 *
 * Every file under resources/js/pages is a lazy entry of the production
 * build, and `app.blade.php` asks Vite for the entry by its source path. A
 * page that another page imports is no longer only an entry: once it has two
 * importers the bundler folds it into a shared chunk, the manifest loses the
 * key for its source path, and rendering it throws "Unable to locate file in
 * Vite manifest".
 *
 * That happened to the invoice list, which exported a status badge the
 * invoice detail page imported. Nothing failed until the next fresh build,
 * and then only that one page did.
 *
 * Anything two pages share belongs in resources/js/components.
 */

it('keeps pages from importing other pages', function () {
    // dirname(), not base_path(): the Architecture suite runs without a
    // booted application.
    $pagesDir = dirname(__DIR__, 2).'/resources/js/pages';
    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pagesDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! preg_match('/\.tsx?$/', $path)) {
            continue;
        }

        // A test importing the page it tests is the point of the test, and
        // tests are left out of the build by the glob in app.tsx.
        if (str_contains($path, '/__tests__/')) {
            continue;
        }

        $contents = (string) file_get_contents($path);

        // Relative imports, and the alias spelled out. Everything a page may
        // import lives outside pages/ and is reached through `@/`.
        preg_match_all('/from\s+[\'"](\.{1,2}\/[^\'"]*|@\/pages\/[^\'"]*)[\'"]/', $contents, $matches);

        foreach ($matches[1] as $import) {
            $offenders[] = sprintf(
                '  %s imports %s',
                str_replace($pagesDir.'/', '', $path),
                $import,
            );
        }
    }

    expect($offenders)->toBe([], "Pages must not import from pages:\n".implode("\n", $offenders));
});

it('leaves page tests out of the production build', function () {
    $app = (string) file_get_contents(dirname(__DIR__, 2).'/resources/js/app.tsx');

    expect($app)->toContain("'!./pages/**/__tests__/**'");
});
