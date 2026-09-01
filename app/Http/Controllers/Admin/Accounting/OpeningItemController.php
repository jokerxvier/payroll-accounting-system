<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\RecordOpeningItems;
use App\Exports\OpeningItemTemplateExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\OpeningItemImportRequest;
use App\Imports\OpeningItemImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\School;
use App\Services\Accounting\Reports\OpeningItemReconciliation;
use App\Services\Accounting\Reports\OpeningItemReconciliationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Multitenancy\Models\Tenant;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 5 Slice 9 — the documents behind the opening AR and AP.
 *
 * Same four steps as {@see OpeningBalanceController}, and the same session
 * token, because it is the same job continued: that one states the receivable,
 * this one lists what it is made of.
 *
 *   1. GET  opening-items                  → upload form + preview
 *   2. GET  opening-items/template         → the worksheet to fill in
 *   3. POST opening-items/preview          → parse, stash under a token
 *   4. POST opening-items/confirm/{token}  → record the documents
 *
 * **The reconciliation is computed on the preview, not just afterwards.** The
 * figure worth having is whether the documents add up to the control account,
 * and learning that they do not *after* recording a hundred invoices is far
 * worse than learning it before. A difference does not block the confirm — it
 * means the client's previous system disagreed with itself, which is a finding
 * they need rather than a reason they cannot migrate.
 */
final class OpeningItemController extends Controller
{
    public function __construct(
        private readonly OpeningItemReconciliationService $reconciliation,
    ) {}

    public function index(): Response
    {
        Gate::authorize('postOpeningBalance', JournalEntry::class);

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('opening_item_import.parsed');

        $school = Tenant::current();
        $booksOpenedOn = $school instanceof School
            ? $school->books_opened_on?->toDateString()
            : null;

        return Inertia::render('admin/accounting/opening-items/index', [
            'parsed' => $parsed,
            'token' => session('opening_item_import.token'),
            'sourceFilename' => session('opening_item_import.source_filename'),
            'booksOpenedOn' => $booksOpenedOn,
            'summary' => $parsed === null ? null : $this->summarise($parsed),
            // Against the parsed file when previewing, against what is already
            // recorded otherwise — so the panel answers the question the user
            // is actually asking at that moment.
            'reconciliation' => array_map(
                static fn (OpeningItemReconciliation $row): array => $row->toArray(),
                array_values(array_filter(
                    $this->reconciliation->forCurrentSchool(
                        $parsed === null ? null : $this->pendingTotals($parsed),
                    ),
                    static fn (OpeningItemReconciliation $row): bool => $row->isSignificant(),
                )),
            ),
            'recordedCount' => Invoice::query()->openingItems()->count(),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('postOpeningBalance', JournalEntry::class);

        return Excel::download(
            new OpeningItemTemplateExport,
            'opening-items-template.xlsx',
        );
    }

    public function preview(OpeningItemImportRequest $request): RedirectResponse
    {
        $import = new OpeningItemImport;
        Excel::import($import, $request->file('file'));

        session([
            'opening_item_import.parsed' => $import->parsed(),
            'opening_item_import.token' => (string) Str::uuid(),
            'opening_item_import.source_filename' => $request->file('file')?->getClientOriginalName(),
        ]);

        return redirect()->route('admin.opening-items.index');
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        Gate::authorize('postOpeningBalance', JournalEntry::class);

        if (session('opening_item_import.token') !== $token) {
            return $this->refuse('token', 'Preview is no longer valid. Re-upload the worksheet.');
        }

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('opening_item_import.parsed');

        if ($parsed === null) {
            return $this->refuse('token', 'No parsed worksheet in session. Upload again.');
        }

        // A row with errors is never applied, and the file is refused whole
        // rather than part-recorded. A partial sub-ledger is worse than none:
        // it reconciles to nothing, and the difference it reports would send
        // somebody looking for a discrepancy that is really just the rows
        // that failed.
        $withErrors = array_filter($parsed, static fn (array $r): bool => ! empty($r['errors']));

        if ($withErrors !== []) {
            return $this->refuse('file', sprintf(
                '%d row%s still need fixing. Correct the worksheet and upload it again.',
                count($withErrors),
                count($withErrors) === 1 ? '' : 's',
            ));
        }

        try {
            $recorded = app(RecordOpeningItems::class)->execute(
                $this->itemsFrom($parsed),
                (int) $request->user()?->getKey(),
            );
        } catch (DomainException $e) {
            return $this->refuse('file', $e->getMessage());
        }

        AuditLog::query()->create([
            'auditable_type' => School::class,
            'auditable_id' => Tenant::current()?->getKey(),
            'action' => 'accounting.opening_items_imported',
            'before' => null,
            'after' => [
                'source_filename' => session('opening_item_import.source_filename'),
                'items_recorded' => $recorded->count(),
                'total_centavos' => (int) $recorded->sum('total_centavos'),
                'already_paid_centavos' => (int) $recorded->sum('amount_paid_centavos'),
            ],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('opening_item_import');

        return redirect()
            ->route('admin.opening-items.index')
            ->with('success', sprintf(
                '%d open item%s recorded.',
                $recorded->count(),
                $recorded->count() === 1 ? '' : 's',
            ));
    }

    private function refuse(string $key, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.opening-items.index')
            ->withErrors([$key => $message]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return list<array{
     *     type: string,
     *     contact_id: int,
     *     number: ?string,
     *     issue_date: string,
     *     due_date: ?string,
     *     total_centavos: int,
     *     amount_paid_centavos: int,
     *     student_name: ?string,
     * }>
     */
    private function itemsFrom(array $parsed): array
    {
        $items = [];

        foreach ($parsed as $row) {
            $contactId = $row['contact_id'] ?? null;
            $type = $row['type'] ?? null;
            $issueDate = $row['issue_date'] ?? null;

            if (! is_int($contactId) || ! is_string($type) || ! is_string($issueDate)) {
                continue;
            }

            $items[] = [
                'type' => $type,
                'contact_id' => $contactId,
                'number' => is_string($row['number'] ?? null) ? $row['number'] : null,
                'issue_date' => $issueDate,
                'due_date' => is_string($row['due_date'] ?? null) ? $row['due_date'] : null,
                'total_centavos' => (int) ($row['total_centavos'] ?? 0),
                'amount_paid_centavos' => (int) ($row['amount_paid_centavos'] ?? 0),
                'student_name' => is_string($row['student_name'] ?? null) ? $row['student_name'] : null,
            ];
        }

        return $items;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array<int, array{type: string, total_centavos: int, amount_paid_centavos: int}>
     */
    private function pendingTotals(array $parsed): array
    {
        $totals = [];

        foreach ($parsed as $row) {
            $type = $row['type'] ?? null;

            if (is_string($type)) {
                $totals[] = [
                    'type' => $type,
                    'total_centavos' => (int) ($row['total_centavos'] ?? 0),
                    'amount_paid_centavos' => (int) ($row['amount_paid_centavos'] ?? 0),
                ];
            }
        }

        return $totals;
    }

    /**
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array{
     *     row_count: int,
     *     error_count: int,
     *     warning_count: int,
     *     total_centavos: int,
     *     already_paid_centavos: int,
     *     outstanding_centavos: int,
     *     books_are_open: bool,
     * }
     */
    private function summarise(array $parsed): array
    {
        $total = 0;
        $paid = 0;
        $errors = 0;
        $warnings = 0;

        foreach ($parsed as $row) {
            $total += (int) ($row['total_centavos'] ?? 0);
            $paid += (int) ($row['amount_paid_centavos'] ?? 0);
            $errors += count((array) ($row['errors'] ?? []));
            $warnings += count((array) ($row['warnings'] ?? []));
        }

        $school = Tenant::current();

        return [
            'row_count' => count($parsed),
            'error_count' => $errors,
            'warning_count' => $warnings,
            'total_centavos' => $total,
            'already_paid_centavos' => $paid,
            // What the ageing report will show once these are recorded.
            'outstanding_centavos' => $total - $paid,
            'books_are_open' => $school instanceof School && $school->books_opened_on !== null,
        ];
    }
}
