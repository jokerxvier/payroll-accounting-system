<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\PostOpeningBalances;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exports\OpeningBalanceTemplateExport;
use App\Http\Controllers\Admin\EmployeeBulkImportController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\OpeningBalanceConfirmRequest;
use App\Http\Requests\Admin\Accounting\OpeningBalanceImportRequest;
use App\Imports\OpeningBalanceImport;
use App\Models\Pas\AuditLog;
use App\Models\Pas\JournalEntry;
use App\Services\Accounting\AccountingPeriodGuard;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Phase 5 Slice 9 — the cutover import.
 *
 * Four steps, mirroring {@see EmployeeBulkImportController}:
 *
 *   1. GET  opening-balances                  → upload form + preview
 *   2. GET  opening-balances/template         → the worksheet to fill in
 *   3. POST opening-balances/preview          → parse, stash under a token
 *   4. POST opening-balances/confirm/{token}  → post the snapshot
 *
 * It copies that controller's flow and NOT its authorization: employee bulk
 * import gates on inline `hasAnyRole`, which bypasses the `Gate::before`
 * platform-admin short-circuit and has to name the role by hand. The
 * accounting module goes through policies, so this one does too.
 *
 * The preview is where the two hard refusals become legible rather than
 * fatal. An unbalanced sheet and a cutover date no open period covers both
 * throw from the posting action — but a stack trace at the end of a
 * reconciliation is a poor way to learn that the difference is ₱4,000, so
 * the page computes and shows both before the user commits.
 */
final class OpeningBalanceController extends Controller
{
    public function __construct(
        private readonly AccountingPeriodGuard $periodGuard,
    ) {}

    public function index(): Response
    {
        Gate::authorize('postOpeningBalance', JournalEntry::class);

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('opening_balance_import.parsed');
        $cutoverDate = session('opening_balance_import.cutover_date');

        return Inertia::render('admin/accounting/opening-balances/index', [
            'parsed' => $parsed,
            'token' => session('opening_balance_import.token'),
            'sourceFilename' => session('opening_balance_import.source_filename'),
            'cutoverDate' => $cutoverDate,
            'summary' => $parsed === null
                ? null
                : $this->summarise($parsed, is_string($cutoverDate) ? $cutoverDate : null),
            'existingSnapshot' => $this->liveSnapshot(),
        ]);
    }

    public function template(): BinaryFileResponse
    {
        Gate::authorize('postOpeningBalance', JournalEntry::class);

        return Excel::download(
            new OpeningBalanceTemplateExport,
            'opening-balances-template.xlsx',
        );
    }

    public function preview(OpeningBalanceImportRequest $request): RedirectResponse
    {
        $import = new OpeningBalanceImport;
        Excel::import($import, $request->file('file'));

        $token = (string) Str::uuid();

        session([
            'opening_balance_import.parsed' => $import->parsed(),
            'opening_balance_import.token' => $token,
            'opening_balance_import.source_filename' => $request->file('file')?->getClientOriginalName(),
            'opening_balance_import.cutover_date' => CarbonImmutable::parse(
                (string) $request->validated('cutover_date')
            )->toDateString(),
        ]);

        return redirect()->route('admin.opening-balances.index');
    }

    public function confirm(OpeningBalanceConfirmRequest $request, string $token): RedirectResponse
    {
        if (session('opening_balance_import.token') !== $token) {
            return redirect()
                ->route('admin.opening-balances.index')
                ->withErrors(['token' => 'Preview is no longer valid. Re-upload the worksheet.']);
        }

        /** @var array<int, array<string, mixed>>|null $parsed */
        $parsed = session('opening_balance_import.parsed');
        $cutoverDate = session('opening_balance_import.cutover_date');

        if ($parsed === null || ! is_string($cutoverDate)) {
            return redirect()
                ->route('admin.opening-balances.index')
                ->withErrors(['token' => 'No parsed worksheet in session. Upload again.']);
        }

        // A row with errors is never applied. Refusing the whole file rather
        // than importing the clean subset is the deliberate choice here: a
        // partial opening balance is an unbalanced one, and it would post a
        // plug to Retained Earnings covering rows the user believed were
        // included.
        $withErrors = array_filter($parsed, static fn (array $r): bool => ! empty($r['errors']));

        if ($withErrors !== []) {
            return redirect()
                ->route('admin.opening-balances.index')
                ->withErrors(['file' => sprintf(
                    '%d row%s still need fixing. Correct the worksheet and upload it again.',
                    count($withErrors),
                    count($withErrors) === 1 ? '' : 's',
                )]);
        }

        $lines = [];
        foreach ($parsed as $row) {
            $accountId = $row['account_id'] ?? null;

            if (! is_int($accountId)) {
                continue;
            }

            $lines[] = [
                'account_id' => $accountId,
                'debit_centavos' => (int) ($row['debit_centavos'] ?? 0),
                'credit_centavos' => (int) ($row['credit_centavos'] ?? 0),
            ];
        }

        try {
            $entry = app(PostOpeningBalances::class)->execute(
                CarbonImmutable::parse($cutoverDate),
                $lines,
                (int) $request->user()?->getKey(),
                $request->shouldPlug(),
            );
        } catch (ClosedAccountingPeriodException|DomainException $e) {
            return redirect()
                ->route('admin.opening-balances.index')
                ->withErrors(['file' => $e->getMessage()]);
        }

        AuditLog::query()->create([
            'auditable_type' => JournalEntry::class,
            'auditable_id' => $entry->getKey(),
            'action' => 'accounting.opening_balances_imported',
            'before' => null,
            'after' => [
                'source_filename' => session('opening_balance_import.source_filename'),
                'cutover_date' => $cutoverDate,
                'entry_number' => $entry->entry_number,
                // Counted off the posted entry, not off `$lines`: the action
                // drops rows that are zero on both sides, and a template
                // returned with untouched accounts still in it is the normal
                // case. Counting the input would record accounts that were
                // never opened.
                'accounts_opened' => $entry->lines()->count(),
                'plugged_to_retained_earnings' => $request->shouldPlug(),
                'total_debit_centavos' => $entry->total_debit_centavos,
                'total_credit_centavos' => $entry->total_credit_centavos,
            ],
            'actor_id' => $request->user()?->getKey(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        session()->forget('opening_balance_import');

        return redirect()
            ->route('admin.journal-entries.show', $entry)
            ->with('success', sprintf(
                'Opening balances posted as %s, dated %s.',
                $entry->entry_number,
                $cutoverDate,
            ));
    }

    /**
     * Everything the page needs to decide whether confirm is allowed.
     *
     * Computed server-side rather than in the React page so the figure the
     * user is shown and the figure the action checks come from the same
     * arithmetic — a difference the page calculated itself could disagree
     * with the one that gets posted.
     *
     * @param  array<int, array<string, mixed>>  $parsed
     * @return array{
     *     total_debit_centavos: int,
     *     total_credit_centavos: int,
     *     difference_centavos: int,
     *     row_count: int,
     *     error_count: int,
     *     period_is_open: bool,
     * }
     */
    private function summarise(array $parsed, ?string $cutoverDate): array
    {
        $debits = 0;
        $credits = 0;
        $errors = 0;

        foreach ($parsed as $row) {
            $debits += (int) ($row['debit_centavos'] ?? 0);
            $credits += (int) ($row['credit_centavos'] ?? 0);

            if (! empty($row['errors'])) {
                $errors++;
            }
        }

        return [
            'total_debit_centavos' => $debits,
            'total_credit_centavos' => $credits,
            'difference_centavos' => $debits - $credits,
            'row_count' => count($parsed),
            'error_count' => $errors,
            'period_is_open' => $cutoverDate !== null
                && $this->periodGuard->isOpenOn(CarbonImmutable::parse($cutoverDate)),
        ];
    }

    /**
     * The standing snapshot, if there is one.
     *
     * @return array{entry_number: string|null, date: string, id: int}|null
     */
    private function liveSnapshot(): ?array
    {
        $entry = JournalEntry::query()
            ->openingBalance()
            ->posted()
            ->whereNull('reversed_at')
            ->whereNull('reversal_of_entry_id')
            ->first();

        if ($entry === null) {
            return null;
        }

        return [
            'id' => (int) $entry->getKey(),
            'entry_number' => $entry->entry_number,
            'date' => $entry->date->toDateString(),
        ];
    }
}
