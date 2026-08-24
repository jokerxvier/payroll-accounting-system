<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Exports\GeneralLedgerExport;
use App\Exports\JournalReportExport;
use App\Exports\TrialBalanceExport;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Controller;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Policies\Pas\JournalEntryPolicy;
use App\Services\Accounting\Reports\LedgerReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 5 Slice 8a — the three reports that read the ledger directly:
 * Trial Balance, General Ledger, and Journal Report.
 *
 * All three are read-only views over posted journal entries, so they
 * authorize as one thing — "may this user read the ledger" — against
 * {@see JournalEntryPolicy::viewAny()}. Going through the
 * policy rather than an inline role list is deliberate: it keeps the reports
 * and the journal page from drifting apart on who may see the books, and it
 * picks up the `Gate::before` platform-admin short-circuit for free.
 *
 * No state predicates are involved — nothing here mutates anything — so the
 * "predicate before policy" habit the invoice and payment controllers carry
 * has nothing to guard against and is correctly absent.
 */
final class LedgerReportController extends Controller
{
    /** @var list<string> */
    private const EXPORT_FORMATS = ['xlsx', 'csv', 'pdf'];

    public function __construct(private readonly LedgerReportService $reports) {}

    public function trialBalance(Request $request): Response
    {
        $this->authorizeLedgerRead();

        [$from, $to] = $this->resolveDateRange($request);
        $includeEmpty = $request->boolean('include_empty');

        $trialBalance = $this->reports->trialBalance($from, $to, $includeEmpty);

        return Inertia::render('admin/accounting/reports/trial-balance', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'include_empty' => $includeEmpty,
            ],
            'rows' => $trialBalance->rowsToArray(),
            'totals' => $trialBalance->totalsToArray(),
        ]);
    }

    public function trialBalanceExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeLedgerRead();

        $format = $this->resolveExportFormat($request);
        [$from, $to] = $this->resolveDateRange($request);
        $trialBalance = $this->reports->trialBalance($from, $to, $request->boolean('include_empty'));

        $filename = sprintf('trial-balance_%s_%s.%s', $from->toDateString(), $to->toDateString(), $format);

        if ($format === 'pdf') {
            return $this->renderPdf('reports.trial-balance-pdf', [
                'trialBalance' => $trialBalance,
            ], $filename);
        }

        return Excel::download(
            new TrialBalanceExport($trialBalance),
            $filename,
            $this->writerTypeFor($format),
        );
    }

    public function generalLedger(Request $request): Response
    {
        $this->authorizeLedgerRead();

        [$from, $to] = $this->resolveDateRange($request);
        $account = $this->resolveAccount($request);

        return Inertia::render('admin/accounting/reports/general-ledger', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'account_id' => $account?->getKey(),
            ],
            'accountOptions' => $this->accountOptions(),
            'ledger' => $account === null
                ? null
                : $this->reports->accountLedger($account, $from, $to)->toArray(),
        ]);
    }

    public function generalLedgerExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeLedgerRead();

        $format = $this->resolveExportFormat($request);
        [$from, $to] = $this->resolveDateRange($request);
        $account = $this->resolveAccount($request);

        // An export with no account chosen has nothing to export. 404 rather
        // than an empty file: a blank general ledger is indistinguishable from
        // an account that genuinely had no movement, and one of those is a
        // mistake worth surfacing.
        abort_if($account === null, 404, 'Choose an account before exporting its ledger.');

        $ledger = $this->reports->accountLedger($account, $from, $to);

        $filename = sprintf(
            'general-ledger_%s_%s_%s.%s',
            $account->code,
            $from->toDateString(),
            $to->toDateString(),
            $format,
        );

        if ($format === 'pdf') {
            return $this->renderPdf('reports.general-ledger-pdf', [
                'ledger' => $ledger,
            ], $filename);
        }

        return Excel::download(
            new GeneralLedgerExport($ledger),
            $filename,
            $this->writerTypeFor($format),
        );
    }

    public function journal(Request $request): Response
    {
        $this->authorizeLedgerRead();

        [$from, $to] = $this->resolveDateRange($request);
        $entries = $this->reports->journal($from, $to);

        return Inertia::render('admin/accounting/reports/journal-report', [
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'entries' => $this->journalToArray($entries),
            'totals' => [
                'entry_count' => $entries->count(),
                'debit_centavos' => (int) $entries->sum('total_debit_centavos'),
                'credit_centavos' => (int) $entries->sum('total_credit_centavos'),
            ],
        ]);
    }

    public function journalExport(Request $request): BinaryFileResponse|HttpResponse
    {
        $this->authorizeLedgerRead();

        $format = $this->resolveExportFormat($request);
        [$from, $to] = $this->resolveDateRange($request);
        $entries = $this->reports->journal($from, $to);

        $filename = sprintf('journal-report_%s_%s.%s', $from->toDateString(), $to->toDateString(), $format);

        if ($format === 'pdf') {
            return $this->renderPdf('reports.journal-report-pdf', [
                'entries' => $entries,
                'from' => $from,
                'to' => $to,
            ], $filename);
        }

        return Excel::download(
            new JournalReportExport($entries, $from, $to),
            $filename,
            $this->writerTypeFor($format),
        );
    }

    /**
     * Reading a report is reading the ledger. Authorizing against the journal
     * policy rather than a private role list is what keeps the two from
     * drifting — a report that showed figures the journal page hides would be
     * the same disclosure by another route.
     */
    private function authorizeLedgerRead(): void
    {
        Gate::authorize('viewAny', JournalEntry::class);
    }

    /**
     * Flattened for the wire. The entry's own stored totals are sent rather
     * than re-summed from the lines: if the two ever disagree, the Trial
     * Balance is the report that says so, and quietly papering over it here
     * would remove the evidence.
     *
     * @param  Collection<int, JournalEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function journalToArray(Collection $entries): array
    {
        return $entries->map(fn (JournalEntry $entry): array => [
            'id' => $entry->getKey(),
            'entry_number' => $entry->entry_number,
            'date' => $entry->date->toDateString(),
            'reference' => $entry->reference,
            'narration' => $entry->narration,
            'source_type' => $entry->source_type,
            'is_reversal' => $entry->isReversal(),
            'total_debit_centavos' => $entry->total_debit_centavos,
            'total_credit_centavos' => $entry->total_credit_centavos,
            'lines' => $entry->lines->map(fn ($line): array => [
                'id' => $line->getKey(),
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'description' => $line->description,
                'debit_centavos' => $line->debit_centavos,
                'credit_centavos' => $line->credit_centavos,
            ])->values()->all(),
        ])->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function accountOptions(): array
    {
        return ChartOfAccount::query()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'is_active'])
            ->map(fn (ChartOfAccount $account): array => [
                'id' => $account->getKey(),
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type,
                'is_active' => $account->is_active,
            ])
            ->values()
            ->all();
    }

    /**
     * The account whose ledger is being read, or null when none is chosen yet.
     *
     * Resolved through the tenant-scoped model, so an id belonging to another
     * school reads as "not chosen" rather than as somebody else's ledger.
     */
    private function resolveAccount(Request $request): ?ChartOfAccount
    {
        $id = $request->integer('account_id');

        return $id === 0 ? null : ChartOfAccount::query()->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function renderPdf(string $view, array $data, string $filename): HttpResponse
    {
        return Pdf::loadView($view, [
            ...$data,
            'generatedAt' => CarbonImmutable::now(),
        ])->setPaper('a4', 'landscape')->download($filename);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveDateRange(Request $request): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        $from = $request->date('from')
            ? CarbonImmutable::parse((string) $request->input('from'))->startOfDay()
            : $today->startOfMonth();
        $to = $request->date('to')
            ? CarbonImmutable::parse((string) $request->input('to'))->startOfDay()
            : $today->endOfMonth()->startOfDay();

        if ($to->lessThan($from)) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
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
