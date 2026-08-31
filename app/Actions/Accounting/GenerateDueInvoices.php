<?php

declare(strict_types=1);

namespace App\Actions\Accounting;

use App\Console\Commands\GenerateRecurringInvoices;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use App\Models\Pas\RecurringInvoicePeriod;
use App\Models\Pas\School;
use App\Models\Pas\TaxRate;
use App\Services\Accounting\InvoiceBillingRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Multitenancy\Models\Tenant;
use Throwable;

/**
 * Turns schedules that are due into draft invoices, for the current tenant.
 *
 * **Drafts, never postings.** Nothing here reaches the ledger, so an unattended
 * job cannot touch the books, cannot hit `AccountingPeriodGuard`, and cannot
 * issue a claim against a parent. A wrong template produces a bad draft, and a
 * bad draft is deletable. `ApproveInvoice` — with a person behind it — is what
 * makes a document real.
 *
 * **One family's broken schedule must not stop the other forty-one.** Every
 * schedule is isolated: a failure records a sentence on the row and the loop
 * moves on. The counts returned follow the shape `ImportLmsGuardians`
 * established for "did some, not all".
 *
 * Assumes a tenant is current. The caller pivots — see
 * {@see GenerateRecurringInvoices}.
 */
final class GenerateDueInvoices
{
    /**
     * More than a year of arrears is a misconfiguration rather than catch-up,
     * and hundreds of drafts would bury the ones that matter.
     */
    private const MAX_PERIODS_PER_RUN = 12;

    public function __construct(
        private readonly CreateInvoiceDraft $draft,
        private readonly InvoiceBillingRules $rules,
    ) {}

    /**
     * @return array{generated: int, skipped: int, failed: int, schedules: int}
     */
    public function execute(CarbonImmutable $on, bool $dryRun = false): array
    {
        // Flattened to a bare calendar date before anything compares it.
        // The caller resolves "today" in Manila, so `$on` arrives as midnight
        // +08:00 — which is 16:00 the *previous* day in UTC, where the date
        // casts live. Comparing those instants directly makes an invoice dated
        // the 1st look later than a run asked for the 1st, and every schedule
        // is silently skipped. "As at this date" is a date, not a moment.
        $on = CarbonImmutable::parse($on->toDateString());

        $counts = ['generated' => 0, 'skipped' => 0, 'failed' => 0, 'schedules' => 0];

        $floor = $this->catchUpFloor();

        $schedules = RecurringInvoice::query()
            ->with('lines')
            ->dueOn($on)
            ->orderBy('id')
            ->get();

        foreach ($schedules as $schedule) {
            $counts['schedules']++;

            try {
                $made = $this->generateFor($schedule, $on, $floor, $dryRun);

                $counts['generated'] += $made;

                if ($made === 0) {
                    $counts['skipped']++;
                }
            } catch (Throwable $e) {
                $counts['failed']++;
                $this->recordFailure($schedule, $e);
            }
        }

        return $counts;
    }

    /**
     * Every period this schedule owes, up to and including `$on`.
     *
     * @return int How many invoices were raised.
     */
    private function generateFor(
        RecurringInvoice $schedule,
        CarbonImmutable $on,
        ?CarbonImmutable $floor,
        bool $dryRun,
    ): int {
        $reason = $this->cannotGenerate($schedule);

        if ($reason !== null) {
            // Not an exception: a payer who stopped being a customer is an
            // ordinary state of the world, not a bug. Recorded where an
            // operator will see it, and the run carries on.
            $this->recordSkip($schedule, $reason);

            return 0;
        }

        // Where this schedule has got to. Derived from the claims rather than
        // from `next_run_on`, so a cursor edited by hand still cannot re-bill
        // a period that has already been billed.
        $startIndex = $schedule->periods()->count();
        $made = 0;

        for ($offset = 0; $offset < self::MAX_PERIODS_PER_RUN; $offset++) {
            $index = $startIndex + $offset;
            $issueDate = $schedule->issueDateForPeriod($index);

            if ($issueDate->greaterThan($on)) {
                break;
            }

            if ($schedule->endsBefore($issueDate)) {
                // Nothing left to bill. Retire it rather than re-selecting it
                // every night to do nothing.
                if (! $dryRun) {
                    $schedule->forceFill(['is_active' => false])->save();
                }

                break;
            }

            // A draft backdated into a closed month, or before the school's
            // books opened, can never be approved — it would sit in the list
            // forever with nothing saying why. Claim the period so it is not
            // retried every night, but raise nothing.
            if ($floor !== null && $issueDate->lessThan($floor)) {
                if (! $dryRun) {
                    $this->claim($schedule, $issueDate, sprintf(
                        'No invoice raised: %s is before this school\'s books opened, so the draft could never have been approved.',
                        $issueDate->toFormattedDateString(),
                    ));
                    $this->passOver($schedule, $index);
                }

                continue;
            }

            if ($dryRun) {
                $made++;

                continue;
            }

            if ($this->raise($schedule, $issueDate, $index)) {
                $made++;
            }
        }

        return $made;
    }

    /**
     * Claim the period, then raise the invoice, in one transaction.
     *
     * The claim and the document commit together or not at all. Split apart, a
     * crash between them either double-bills — which the unique index would
     * catch — or silently skips a period forever, which nothing would catch.
     *
     * One transaction per invoice, never one per school:
     * `InvoiceNumberAllocator` holds a `lockForUpdate` on the top of the number
     * range, so a school-long transaction would block every manual invoice
     * creation for that school until the run finished.
     *
     * @return bool False when another run already claimed this period.
     */
    private function raise(RecurringInvoice $schedule, CarbonImmutable $issueDate, int $index): bool
    {
        try {
            DB::transaction(function () use ($schedule, $issueDate, $index): void {
                $claim = $this->claim($schedule, $issueDate);

                $invoice = $this->draft->execute(
                    $this->headerFor($schedule, $issueDate),
                    $this->linesFor($schedule),
                    ['recurring_invoice_id' => $schedule->getKey()],
                );

                $claim->forceFill(['invoice_id' => $invoice->getKey()])->save();

                $this->advance($schedule, $index);
            });
        } catch (QueryException $e) {
            // The unique on the claim table fired: another run got here first.
            // Not an error — it is the guarantee doing its job.
            if ($this->isDuplicateClaim($e)) {
                return false;
            }

            throw $e;
        }

        return true;
    }

    private function claim(
        RecurringInvoice $schedule,
        CarbonImmutable $issueDate,
        ?string $note = null,
    ): RecurringInvoicePeriod {
        return RecurringInvoicePeriod::create([
            'recurring_invoice_id' => $schedule->getKey(),
            'period' => $schedule->periodKeyFor($issueDate),
            'invoice_id' => null,
            'note' => $note,
            'claimed_at' => now(),
        ]);
    }

    /**
     * Move the cursor past the period just handled.
     *
     * `next_run_on` is recomputed from `starts_on` plus a period count, never
     * by adding a month to the previous date: `addMonth()` on 31 January gives
     * 28 February and then 28 *March*, because the day sticks once clamped.
     */
    private function advance(RecurringInvoice $schedule, int $index): void
    {
        $schedule->forceFill([
            'next_run_on' => $schedule->issueDateForPeriod($index + 1),
            'last_generated_on' => $schedule->issueDateForPeriod($index),
            'generated_count' => $schedule->generated_count + 1,
            'last_error' => null,
            'last_error_at' => null,
        ])->save();
    }

    /**
     * Move past a period that was claimed but raised nothing.
     *
     * The cursor advances — the period is spoken for and must not be retried
     * every night — but `generated_count` and `last_generated_on` do not,
     * because nothing was generated. A schedule that silently claimed a period
     * and reported itself as having billed it is how an unbilled month goes
     * unnoticed.
     *
     * The reason lives on the claim row, not here: one run can pass over
     * August and then bill September successfully, and the schedule has room
     * only for its latest state.
     */
    private function passOver(RecurringInvoice $schedule, int $index): void
    {
        $schedule->forceFill([
            'next_run_on' => $schedule->issueDateForPeriod($index + 1),
        ])->save();
    }

    /**
     * Why this schedule cannot raise anything right now, or null.
     *
     * Re-checked on **every** run, not only when the schedule was saved.
     * Guardians change and students transfer; a schedule validated once in
     * August and left running for a year is how a stranger receives a
     * tokenised pay link for someone else's child.
     */
    private function cannotGenerate(RecurringInvoice $schedule): ?string
    {
        if ($schedule->lines->isEmpty()) {
            return 'This schedule has no lines, so there is nothing to charge.';
        }

        $contact = Contact::query()->find($schedule->contact_id);

        if ($contact === null) {
            return 'The payer on this schedule no longer exists.';
        }

        return $this->rules->contactCannotBeBilled($contact, $schedule->type)
            ?? $this->rules->payerIsNotLinkedToStudent($schedule->lms_student_id, $schedule->contact_id)
            ?? $this->linesAreUnusable($schedule);
    }

    /**
     * The tenant-scoping the FormRequest does and a headless run otherwise
     * would not.
     *
     * `InvoiceRequest` scopes its `exists` rules by `school_id`; nothing does
     * that here. A template line pointing at another school's chart row would
     * otherwise generate an invoice that passes every other check and posts to
     * a foreign account the day someone approves it.
     */
    private function linesAreUnusable(RecurringInvoice $schedule): ?string
    {
        $accountIds = $schedule->lines->pluck('account_id')->unique()->all();

        // BelongsToTenant scopes both queries, so a row belonging to another
        // school simply does not come back.
        if (ChartOfAccount::query()->whereIn('id', $accountIds)->count() !== count($accountIds)) {
            return 'One of this schedule\'s lines points at an account this school does not have.';
        }

        $taxRateIds = $schedule->lines->pluck('tax_rate_id')->filter()->unique()->all();

        if ($taxRateIds !== [] && TaxRate::query()->whereIn('id', $taxRateIds)->count() !== count($taxRateIds)) {
            return 'One of this schedule\'s lines points at a tax rate this school does not have.';
        }

        return null;
    }

    /**
     * The earliest date a generated draft could ever be approved.
     *
     * Nothing before the books opened, and nothing before the earliest open
     * accounting period. A draft outside that window is un-approvable the
     * moment it is created.
     */
    private function catchUpFloor(): ?CarbonImmutable
    {
        $tenant = Tenant::current();

        /** @var list<CarbonImmutable> $candidates */
        $candidates = [];

        // Read by key rather than off the current-tenant instance. Spatie's
        // makeCurrent() is a no-op when the same tenant is already bound, so
        // whatever was loaded first stays bound for the rest of the process —
        // and a run that started before `books_opened_on` was set would keep
        // seeing it as null and happily backdate drafts nobody can approve.
        $school = $tenant instanceof School
            ? School::query()->whereKey($tenant->getKey())->first()
            : null;

        if ($school instanceof School && $school->books_opened_on !== null) {
            $candidates[] = $school->books_opened_on;
        }

        $earliestOpen = AccountingPeriod::query()
            ->open()
            ->orderBy('start_date')
            ->value('start_date');

        if ($earliestOpen !== null) {
            $candidates[] = CarbonImmutable::parse((string) $earliestOpen);
        }

        if ($candidates === []) {
            return null;
        }

        return array_reduce(
            $candidates,
            static fn (?CarbonImmutable $carry, CarbonImmutable $date): CarbonImmutable => $carry === null || $date->greaterThan($carry)
                ? $date
                : $carry,
        );
    }

    /** @return array<string, mixed> */
    private function headerFor(RecurringInvoice $schedule, CarbonImmutable $issueDate): array
    {
        return [
            'type' => $schedule->type,
            'contact_id' => $schedule->contact_id,
            'lms_student_id' => $schedule->lms_student_id,
            'reference' => $schedule->reference,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $schedule->due_days === null
                ? null
                : $issueDate->addDays($schedule->due_days)->toDateString(),
            'is_vat_inclusive' => $schedule->is_vat_inclusive,
            'notes' => $schedule->notes,
            'terms' => $schedule->terms,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function linesFor(RecurringInvoice $schedule): array
    {
        return $schedule->lines
            ->map(static fn (RecurringInvoiceLine $line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_price_centavos' => $line->unit_price_centavos,
                'account_id' => $line->account_id,
                'tax_rate_id' => $line->tax_rate_id,
            ])
            ->values()
            ->all();
    }

    private function recordSkip(RecurringInvoice $schedule, string $reason): void
    {
        $schedule->forceFill([
            'last_error' => mb_substr($reason, 0, 255),
            'last_error_at' => now(),
        ])->save();
    }

    private function recordFailure(RecurringInvoice $schedule, Throwable $e): void
    {
        report($e);

        try {
            $this->recordSkip($schedule, $e->getMessage());
        } catch (Throwable) {
            // The failure was the database itself. Losing the note is not
            // worth taking the rest of the run down for.
        }
    }

    private function isDuplicateClaim(QueryException $e): bool
    {
        // 23000 covers both MySQL's 1062 and SQLite's constraint failure.
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}
