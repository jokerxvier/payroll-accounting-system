<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| Payroll Float Audit
|--------------------------------------------------------------------------
|
| Money in this system is an integer-centavos value object (`App\ValueObjects\Money`).
| Any use of PHP's `float` type, the `floatval()` cast, or the bare `round()`
| function inside a payroll computation code path is a P0 correctness bug —
| floats silently lose precision and break centavo-exact reconciliation.
|
| This test grep-scans `app/Actions/Payroll/` and `app/Services/Payroll/` for
| forbidden tokens. Pest's native `arch()` API operates on class dependencies
| (use statements, namespaces, inheritance) and cannot detect language-level
| tokens like the `(float)` cast or a bare function call, so a string-level
| scan is the only way to enforce this contract automatically.
|
| Closes the open acceptance criterion at `rules/PLAN.md:213`:
|   "Zero floats in any payroll computation code path".
|
| Allowed exceptions (none currently): if a future commit needs a guarded
| `(int)` cast around `Carbon::diffInDays()` (which itself returns float),
| the cast itself is fine — only `(float)`, `floatval`, and bare `round(`
| are forbidden. The cast is `(int) $x->diffInDays(...)`, not `(float)`.
*/

/**
 * Scan the given directories and return every line matching the regex,
 * keyed by `path:line` for human-readable failure messages. Comments and
 * docblocks are stripped before matching so that explanatory prose
 * mentioning these tokens doesn't trigger the audit.
 *
 * @param  array<int, string>  $directories
 * @return array<string, string>
 */
function payrollScanForPattern(array $directories, string $regex): array
{
    $hits = [];

    $finder = (new Finder)
        ->files()
        ->in($directories)
        ->name('*.php');

    foreach ($finder as $file) {
        $contents = $file->getContents();

        // Strip block comments (/* ... */ and /** ... */) so that prose
        // mentioning forbidden tokens (e.g. a docblock explaining why a
        // cast is safe) does not trigger a false positive. Single-line
        // comments are stripped per-line below.
        $stripped = preg_replace('#/\*.*?\*/#s', '', $contents) ?? $contents;

        $lines = explode("\n", $stripped);

        foreach ($lines as $index => $line) {
            // Drop the trailing single-line comment on each line, if any,
            // so an inline `// floatval is forbidden` prose comment does
            // not match. We split on the first `//` that is not inside a
            // string literal (cheap heuristic: only strip if no quote is
            // open before the `//`).
            $codeOnly = preg_replace('#(^|[^:\'"])//.*$#', '$1', $line) ?? $line;

            if (preg_match($regex, $codeOnly) === 1) {
                // Use a `app/...` relative path derived from the file's real
                // path. We deliberately avoid `base_path()` so this test
                // does not depend on a booted Laravel application — arch
                // tests should be cheap and framework-independent.
                $real = $file->getRealPath();
                $relative = preg_replace('#^.*?/(app/.*)$#', '$1', $real) ?? $real;
                $hits[$relative.':'.($index + 1)] = trim($line);
            }
        }
    }

    return $hits;
}

const PAYROLL_AUDIT_DIRECTORIES = [
    __DIR__.'/../../app/Actions/Payroll',
    __DIR__.'/../../app/Services/Payroll',
];

it('forbids the (float) cast in payroll computation code', function (): void {
    $hits = payrollScanForPattern(PAYROLL_AUDIT_DIRECTORIES, '/\(float\)/');

    expect($hits)->toBe(
        [],
        'Forbidden (float) cast found in payroll computation code; use App\\ValueObjects\\Money instead. '.
        'Hits: '.json_encode($hits, JSON_PRETTY_PRINT),
    );
});

it('forbids the floatval() function in payroll computation code', function (): void {
    $hits = payrollScanForPattern(PAYROLL_AUDIT_DIRECTORIES, '/\bfloatval\s*\(/');

    expect($hits)->toBe(
        [],
        'Forbidden floatval() call found in payroll computation code; use App\\ValueObjects\\Money instead. '.
        'Hits: '.json_encode($hits, JSON_PRETTY_PRINT),
    );
});

it('forbids the bare round() function in payroll computation code', function (): void {
    // `Money::dividedBy()` uses a documented banker's-rounding policy
    // internally; payroll code must never call `round()` directly.
    $hits = payrollScanForPattern(PAYROLL_AUDIT_DIRECTORIES, '/(?<![\w\\\\>])round\s*\(/');

    expect($hits)->toBe(
        [],
        'Forbidden round() call found in payroll computation code; use Money::dividedBy() (banker\'s rounding) instead. '.
        'Hits: '.json_encode($hits, JSON_PRETTY_PRINT),
    );
});

it('forbids `: float` return types and `float $param` declarations in payroll computation code', function (): void {
    // Catches both return-type declarations (`: float` / `: ?float`) and
    // typed parameters (`float $foo` / `?float $foo`). Excludes the word
    // appearing inside a longer identifier (e.g. `floatval`) via word
    // boundaries.
    $hits = payrollScanForPattern(
        PAYROLL_AUDIT_DIRECTORIES,
        '/(?::\s*\??float\b|\(\s*\??float\s+\$|,\s*\??float\s+\$)/',
    );

    expect($hits)->toBe(
        [],
        'Forbidden float type declaration found in payroll computation code; use App\\ValueObjects\\Money or int (centavos) instead. '.
        'Hits: '.json_encode($hits, JSON_PRETTY_PRINT),
    );
});

it('confirms the audited directories actually exist and contain PHP files', function (): void {
    // Sanity check: a passing audit is meaningless if the scan is silently
    // looking at an empty set. Lock in that the engine is present.
    $files = (new Finder)
        ->files()
        ->in(PAYROLL_AUDIT_DIRECTORIES)
        ->name('*.php');

    expect(iterator_count($files))->toBeGreaterThan(0);
});
