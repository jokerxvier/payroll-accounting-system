<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Concerns\ExportsLedgerReports;
use App\Exports\BalanceSheetExport;
use App\Exports\IncomeStatementExport;
use App\Http\Controllers\Controller;
use App\Policies\Pas\JournalEntryPolicy;
use App\Services\Accounting\FiscalYear;
use App\Services\Accounting\Reports\BalanceSheet;
use App\Services\Accounting\Reports\FinancialStatementService;
use App\Services\Accounting\Reports\StatementColumnResolver;
use App\Services\Accounting\Reports\StatementColumnSet;
use App\Support\DayBoundary;
use App\Support\ReportRange;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 5 Slice 8b — the Balance Sheet and the Income Statement.
 *
 * The same disclosure as the ledger reports — the school's posted entries,
 * subtotalled — so they authorize as the ledger reports do, against
 * {@see JournalEntryPolicy::viewAny()}.
 *
 * Filters are read leniently, as in `LedgerReportController`: a date that
 * cannot be read falls back to a default and a comparison that cannot be
 * made falls back to none, each with a notice on the page. Nothing is refused
 * with a validation error, because the exports are plain download links and
 * a redirect back with errors has nowhere to land.
 */
final class FinancialStatementController extends Controller
{
    use ExportsLedgerReports;

    public function __construct(
        private readonly FinancialStatementService $statements,
        private readonly StatementColumnResolver $columns,
        private readonly FiscalYear $fiscalYear,
    ) {}

    public function balanceSheet(Request $request): Response
    {
        $this->authorizeLedgerRead();

        $includeZero = $request->boolean('include_zero');
        [$sheet, $compare, $notices] = $this->resolveBalanceSheet($request);

        return Inertia::render('admin/accounting/reports/balance-sheet', [
            'filters' => [
                'as_at' => $sheet->primary()->asAt->toDateString(),
                // The comparison that was applied, which a fallback may have
                // changed from the one asked for.
                'compare' => $compare,
                'compare_as_at' => $sheet->comparison()?->asAt->toDateString()
                    ?? DayBoundary::parse($request->query('compare_as_at'))?->toDateString(),
                'include_zero' => $includeZero,
            ],
            'columns' => $sheet->columnsToArray(),
            'hasVariance' => $sheet->hasVariance(),
            'notices' => $notices,
            'lines' => $sheet->linesToArray(),
            'booksOpenedOn' => $this->booksOpenedOn(),
        ]);
    }

    public function balanceSheetExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeLedgerRead();

        $format = $this->resolveExportFormat($request);
        [$sheet, , $notices] = $this->resolveBalanceSheet($request);
        $comparison = $sheet->comparison();

        $filename = sprintf(
            'balance-sheet_%s%s.%s',
            $sheet->primary()->asAt->toDateString(),
            $comparison === null ? '' : '_vs-'.$comparison->asAt->toDateString(),
            $format,
        );

        if ($format === 'pdf') {
            return $this->renderPdf('reports.balance-sheet-pdf', [
                'sheet' => $sheet,
                'notices' => $notices,
            ], $filename, 'portrait');
        }

        return Excel::download(new BalanceSheetExport($sheet), $filename, $this->writerTypeFor($format));
    }

    public function incomeStatement(Request $request): Response
    {
        $this->authorizeLedgerRead();

        $preset = ReportRange::preset($request->query('preset'));
        $columns = $this->resolveColumns($request, $preset);
        $includeZero = $request->boolean('include_zero');

        $statement = $this->statements->incomeStatement($columns, $includeZero);
        $primary = $columns->range;

        return Inertia::render('admin/accounting/reports/income-statement', [
            'filters' => [
                'preset' => $preset,
                'from' => $primary->from->toDateString(),
                'to' => $primary->to->toDateString(),
                // The comparison that was applied, which a fallback may have
                // changed from the one asked for.
                'compare' => $columns->mode,
                'compare_from' => $columns->mode === StatementColumnSet::MODE_CUSTOM
                    ? $columns->comparison()?->from->toDateString()
                    : DayBoundary::parse($request->query('compare_from'))?->toDateString(),
                'compare_to' => $columns->mode === StatementColumnSet::MODE_CUSTOM
                    ? $columns->comparison()?->to->toDateString()
                    : DayBoundary::parse($request->query('compare_to'))?->toDateString(),
                'include_zero' => $includeZero,
            ],
            'columns' => $columns->columnsToArray(),
            'hasVariance' => $columns->hasVariance,
            'notices' => $columns->notices,
            'lines' => $statement->linesToArray(),
            'booksOpenedOn' => $this->booksOpenedOn(),
            'backHref' => $this->resolveReturnTo($request),
        ]);
    }

    public function incomeStatementExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeLedgerRead();

        $format = $this->resolveExportFormat($request);
        $columns = $this->resolveColumns($request, ReportRange::preset($request->query('preset')));
        $statement = $this->statements->incomeStatement($columns, $request->boolean('include_zero'));
        $primary = $columns->range;

        $filename = sprintf(
            'income-statement_%s_%s%s.%s',
            $primary->from->toDateString(),
            $primary->to->toDateString(),
            $columns->mode === StatementColumnSet::MODE_NONE
                ? ''
                : '_vs-'.str_replace('_', '-', $columns->mode),
            $format,
        );

        if ($format === 'pdf') {
            return $this->renderPdf('reports.income-statement-pdf', [
                'statement' => $statement,
            ], $filename);
        }

        return Excel::download(new IncomeStatementExport($statement), $filename, $this->writerTypeFor($format));
    }

    /**
     * The Balance Sheet asked for, the comparison actually applied, and
     * anything that had to be said about it.
     *
     * Drawn up as at the date asked for, or today where the school is. A
     * comparison that cannot be made — a chosen date that is missing or
     * unreadable, or the same date as the statement itself — falls back to
     * none and says so, as the Income Statement does.
     *
     * @return array{0: BalanceSheet, 1: string, 2: list<string>}
     */
    private function resolveBalanceSheet(Request $request): array
    {
        $asAt = DayBoundary::parse($request->query('as_at'))?->startOfDay()
            ?? ReportRange::today();

        $compare = $request->query('compare');
        $compare = is_string($compare) && in_array($compare, BalanceSheet::COMPARISONS, true)
            ? $compare
            : BalanceSheet::COMPARE_NONE;

        $compareAsAt = $this->statements->comparisonDate(
            $compare,
            $asAt,
            DayBoundary::parse($request->query('compare_as_at')),
        );

        $notices = [];

        if ($compare === BalanceSheet::COMPARE_CUSTOM && $compareAsAt === null) {
            $notices[] = 'Choose a date to compare against.';
        }

        if ($compareAsAt !== null && $compareAsAt->equalTo($asAt)) {
            $notices[] = 'The comparison date is the same as the statement date, so there is nothing to compare.';
            $compareAsAt = null;
        }

        if ($compareAsAt === null) {
            $compare = BalanceSheet::COMPARE_NONE;
        }

        return [
            $this->statements->balanceSheet($asAt, $request->boolean('include_zero'), $compareAsAt),
            $compare,
            $notices,
        ];
    }

    private function resolveColumns(Request $request, string $preset): StatementColumnSet
    {
        [$from, $to] = ReportRange::resolve(
            $preset,
            $request->query('from'),
            $request->query('to'),
            $this->fiscalYear,
        );

        $mode = $request->query('compare');
        $booksOpenedOn = $this->booksOpenedOn();

        return $this->columns->resolve(
            $from,
            $to,
            is_string($mode) ? $mode : StatementColumnSet::MODE_NONE,
            DayBoundary::parse($request->query('compare_from')),
            DayBoundary::parse($request->query('compare_to')),
            $booksOpenedOn === null ? null : DayBoundary::parse($booksOpenedOn),
        );
    }
}
