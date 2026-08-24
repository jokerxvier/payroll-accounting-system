<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Accounting;

use App\Actions\Accounting\PostJournalEntry;
use App\Actions\Accounting\ReverseJournalEntry;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Exceptions\UnbalancedJournalEntryException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Accounting\JournalEntryRequest;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin surface for the journal — Phase 5 Slice 2.
 *
 * Unlike the chart of accounts, creating and editing happen on their own
 * pages rather than in a sheet. `RULES.md` §807 prefers a sheet for
 * accounting record edits, and that holds for a single record with a handful
 * of fields; a journal entry is a variable-length grid of account, memo,
 * debit, and credit columns, and squeezing that into a 640px panel would
 * make the debit/credit alignment — the thing an accountant reads down the
 * page — unreadable. The list is paginated for the same reason the chart is
 * not: the journal grows without bound.
 *
 * Posting and reversing are POST transitions on Gate-checked actions with
 * their own defensive guards, mirroring the payroll run lifecycle. Domain
 * refusals (unbalanced, closed period, illegal status) come back as flash
 * errors rather than 500s, because every one of them is something an
 * operator can act on.
 */
final class JournalEntryController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', JournalEntry::class);

        $status = (string) $request->query('status', '');

        $entries = JournalEntry::query()
            ->with(['accountingPeriod:id,code'])
            ->when(
                in_array($status, JournalEntry::STATUSES, true),
                fn ($query) => $query->where('status', $status),
            )
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (JournalEntry $entry): array => $this->summarise($entry));

        return Inertia::render('admin/accounting/journal/index', [
            'entries' => $entries,
            'filters' => ['status' => $status !== '' ? $status : null],
            'can' => [
                'create' => Gate::allows('create', JournalEntry::class),
            ],
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', JournalEntry::class);

        return Inertia::render('admin/accounting/journal/create', [
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function store(JournalEntryRequest $request): RedirectResponse
    {
        Gate::authorize('create', JournalEntry::class);

        /** @var array{date: string, reference: ?string, narration: ?string, lines: array<int, array<string, mixed>>} $data */
        $data = $request->validated();

        $entry = DB::transaction(function () use ($data): JournalEntry {
            $entry = JournalEntry::create([
                'date' => CarbonImmutable::parse($data['date']),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
                'status' => JournalEntry::STATUS_DRAFT,
            ]);

            $this->replaceLines($entry, $data['lines']);

            return $entry;
        });

        return redirect()
            ->route('admin.journal-entries.show', $entry)
            ->with('success', 'Draft journal entry saved. Post it to commit it to the ledger.');
    }

    public function show(JournalEntry $journalEntry): Response
    {
        Gate::authorize('view', $journalEntry);

        $journalEntry->load(['lines.account:id,code,name,type', 'accountingPeriod:id,code,status']);

        return Inertia::render('admin/accounting/journal/show', [
            'entry' => $this->detail($journalEntry),
        ]);
    }

    public function edit(JournalEntry $journalEntry): Response
    {
        Gate::authorize('update', $journalEntry);

        $journalEntry->load('lines');

        return Inertia::render('admin/accounting/journal/edit', [
            'entry' => [
                'id' => $journalEntry->id,
                'date' => $journalEntry->date->toDateString(),
                'reference' => $journalEntry->reference,
                'narration' => $journalEntry->narration,
                'lines' => $journalEntry->lines->map(fn (JournalEntryLine $line): array => [
                    'account_id' => $line->account_id,
                    'debit_centavos' => $line->debit_centavos,
                    'credit_centavos' => $line->credit_centavos,
                    'description' => $line->description,
                ])->values(),
            ],
            'accountOptions' => $this->accountOptions(),
        ]);
    }

    public function update(JournalEntryRequest $request, JournalEntry $journalEntry): RedirectResponse
    {
        Gate::authorize('update', $journalEntry);

        /** @var array{date: string, reference: ?string, narration: ?string, lines: array<int, array<string, mixed>>} $data */
        $data = $request->validated();

        DB::transaction(function () use ($journalEntry, $data): void {
            $journalEntry->update([
                'date' => CarbonImmutable::parse($data['date']),
                'reference' => $data['reference'] ?? null,
                'narration' => $data['narration'] ?? null,
            ]);

            $this->replaceLines($journalEntry, $data['lines']);
        });

        return redirect()
            ->route('admin.journal-entries.show', $journalEntry)
            ->with('success', 'Draft journal entry updated.');
    }

    public function destroy(JournalEntry $journalEntry): RedirectResponse
    {
        Gate::authorize('delete', $journalEntry);

        DB::transaction(fn () => $journalEntry->delete());

        return redirect()
            ->route('admin.journal-entries.index')
            ->with('success', 'Draft journal entry deleted.');
    }

    public function post(JournalEntry $journalEntry, PostJournalEntry $action): RedirectResponse
    {
        Gate::authorize('post', $journalEntry);

        try {
            $posted = $action->execute($journalEntry, (int) auth()->id());
        } catch (UnbalancedJournalEntryException|ClosedAccountingPeriodException|DomainException $e) {
            // Every one of these is something the operator can fix — an
            // imbalance, a closed period, a stale status — so it comes back
            // as guidance rather than a 500.
            return redirect()
                ->route('admin.journal-entries.show', $journalEntry)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.journal-entries.show', $posted)
            ->with('success', "Journal entry {$posted->entry_number} posted.");
    }

    public function reverse(Request $request, JournalEntry $journalEntry, ReverseJournalEntry $action): RedirectResponse
    {
        Gate::authorize('reverse', $journalEntry);

        $reversalDate = $request->filled('reversal_date')
            ? CarbonImmutable::parse((string) $request->input('reversal_date'))
            : null;

        try {
            $reversal = $action->execute(
                $journalEntry,
                (int) auth()->id(),
                $reversalDate,
                (string) $request->input('reason', ''),
            );
        } catch (UnbalancedJournalEntryException|ClosedAccountingPeriodException|DomainException $e) {
            return redirect()
                ->route('admin.journal-entries.show', $journalEntry)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('admin.journal-entries.show', $reversal)
            ->with('success', "Reversing entry {$reversal->entry_number} posted. The original stays on the books and the two now offset.");
    }

    /**
     * Replace an entry's lines wholesale.
     *
     * Deleting and re-inserting rather than diffing: a draft's lines have no
     * identity worth preserving, and the audit trail reads more clearly as
     * "these lines were replaced" than as a scatter of per-line edits.
     * Deletion goes through Eloquent so each removed line still produces its
     * own audit row.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function replaceLines(JournalEntry $entry, array $lines): void
    {
        foreach ($entry->lines()->get() as $existing) {
            $existing->delete();
        }

        foreach (array_values($lines) as $index => $line) {
            JournalEntryLine::create([
                'journal_entry_id' => $entry->getKey(),
                'line_number' => $index + 1,
                'account_id' => (int) $line['account_id'],
                'debit_centavos' => (int) ($line['debit_centavos'] ?? 0),
                'credit_centavos' => (int) ($line['credit_centavos'] ?? 0),
                'description' => $line['description'] ?? null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summarise(JournalEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'date' => $entry->date->toDateString(),
            'reference' => $entry->reference,
            'narration' => $entry->narration,
            'status' => $entry->status,
            'period_code' => $entry->accountingPeriod?->code,
            'total_debit_centavos' => $entry->total_debit_centavos,
            'total_credit_centavos' => $entry->total_credit_centavos,
            'has_been_reversed' => $entry->hasBeenReversed(),
            'is_reversal' => $entry->isReversal(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(JournalEntry $entry): array
    {
        return [
            ...$this->summarise($entry),
            'reversal_of_entry_id' => $entry->reversal_of_entry_id,
            'posted_at' => $entry->posted_at?->toIso8601String(),
            'reversed_at' => $entry->reversed_at?->toIso8601String(),
            'period_status' => $entry->accountingPeriod?->status,
            'lines' => $entry->lines->map(fn (JournalEntryLine $line): array => [
                'id' => $line->id,
                'line_number' => $line->line_number,
                'account_id' => $line->account_id,
                'account_code' => $line->account?->code,
                'account_name' => $line->account?->name,
                'debit_centavos' => $line->debit_centavos,
                'credit_centavos' => $line->credit_centavos,
                'description' => $line->description,
            ])->values(),
            'can' => [
                'update' => Gate::allows('update', $entry),
                'delete' => Gate::allows('delete', $entry),
                'post' => Gate::allows('post', $entry),
                'reverse' => Gate::allows('reverse', $entry),
            ],
        ];
    }

    /**
     * Accounts an entry may post to.
     *
     * Active only — an inactive account is one the school has retired, and
     * offering it would let new postings land somewhere nobody is watching.
     * Existing entries that already reference it still render, because the
     * line carries the account id rather than looking it up through this
     * list.
     *
     * @return Collection<int, ChartOfAccount>
     */
    private function accountOptions(): Collection
    {
        return ChartOfAccount::query()
            ->active()
            ->orderBy('code')
            ->get(['id', 'code', 'name', 'type', 'normal_balance']);
    }
}
