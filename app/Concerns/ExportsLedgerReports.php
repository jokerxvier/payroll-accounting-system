<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Http\Controllers\Admin\ReportsController;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\School;
use App\Policies\Pas\JournalEntryPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Spatie\Multitenancy\Models\Tenant;

/**
 * What every controller reading the posted ledger shares: who may read it,
 * which export formats exist, and how a report becomes a PDF.
 *
 * Lifted out of `LedgerReportController` when the financial statements
 * arrived. The statements are the same disclosure as the ledger reports —
 * the same posted entries, subtotalled — so they must authorize identically
 * and offer the same formats, and two private copies are how that drifts.
 */
trait ExportsLedgerReports
{
    /** @var list<string> */
    private const EXPORT_FORMATS = ['xlsx', 'csv', 'pdf'];

    /**
     * Reading a report is reading the ledger. Authorizing against
     * {@see JournalEntryPolicy::viewAny()} rather than a private role list is
     * what keeps the two from drifting — a report that showed figures the
     * journal page hides would be the same disclosure by another route.
     */
    private function authorizeLedgerRead(): void
    {
        Gate::authorize('viewAny', JournalEntry::class);
    }

    /**
     * The date this school's books were opened, if a cutover snapshot stands.
     *
     * Sent to the balance-bearing reports so a reader can tell an opening
     * figure that was brought in from one that was traded. The figures
     * themselves need no adjustment — `LedgerReportService` sweeps a
     * backdated entry into the opening balance the same as any other posting
     * — but "opening balance" and "opening balance carried in from the
     * client's previous books" are different claims, and only the page can
     * make the second one.
     */
    private function booksOpenedOn(): ?string
    {
        $school = Tenant::current();

        return $school instanceof School
            ? $school->books_opened_on?->toDateString()
            : null;
    }

    /**
     * Where the reader came from, when a report was opened from another
     * report — the address its Back button returns to.
     *
     * The value arrives in the query string, so it is whatever anyone cares
     * to put there, and a Back button is a link the reader trusts. It is
     * accepted only as a path inside the reports themselves: no scheme, no
     * host, no `//` a browser would read as one, no backslash a browser
     * would read as a slash, and no `..` to climb out. Anything else is
     * dropped without comment and the page simply has no Back button.
     */
    private function resolveReturnTo(Request $request): ?string
    {
        $returnTo = $request->query('return_to');

        if (! is_string($returnTo) || $returnTo === '' || strlen($returnTo) > 2000) {
            return null;
        }

        if (! str_starts_with($returnTo, '/admin/reports/')) {
            return null;
        }

        foreach (['//', '\\', '..'] as $forbidden) {
            if (str_contains($returnTo, $forbidden)) {
                return null;
            }
        }

        // Control characters and whitespace have no place in a path, and a
        // newline in one is how a header gets split.
        if (preg_match('/[\x00-\x20\x7f]/', $returnTo) === 1) {
            return null;
        }

        return $returnTo;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  'landscape'|'portrait'  $orientation
     */
    private function renderPdf(
        string $view,
        array $data,
        string $filename,
        string $orientation = 'landscape',
    ): HttpResponse {
        return Pdf::loadView($view, [
            ...$data,
            'generatedAt' => CarbonImmutable::now(),
        ])->setPaper('a4', $orientation)->download($filename);
    }

    /**
     * Mirrors {@see ReportsController}: `xlsx` by
     * default, and an unrecognised value is refused rather than silently
     * falling back to one.
     */
    private function resolveExportFormat(Request $request): string
    {
        $format = strtolower(trim((string) $request->query('format', 'xlsx')));

        if (! in_array($format, self::EXPORT_FORMATS, true)) {
            abort(422, sprintf(
                "Unsupported export format '%s'. Use one of: %s.",
                $format,
                implode(', ', self::EXPORT_FORMATS),
            ));
        }

        return $format;
    }

    private function writerTypeFor(string $format): string
    {
        return $format === 'csv' ? ExcelWriter::CSV : ExcelWriter::XLSX;
    }
}
