<?php

declare(strict_types=1);

use App\Actions\Payroll\PostPayrollRunAction;
use App\Exceptions\ClosedAccountingPeriodException;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\PayPeriod;
use App\Models\Pas\PayrollRun;
use App\Models\Pas\Payslip;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payroll\PayrollLineItem;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * Phase 5 Slice 3 — the payroll → general ledger seam.
 *
 * PLAN.md §11 promised v1 would leave a LedgerPostingService behind. It did
 * not, so this slice builds it. These tests pin the one property that makes
 * it trustworthy: whatever the engine emits, the resulting journal entry
 * balances, and posting twice never doubles the books.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    Payslip::query()->withoutGlobalScopes()->delete();
    PayrollRun::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    // The real default chart, so the config mapping is exercised against the
    // account codes a school actually gets.
    $this->seed(AccountingCatalogSeeder::class);

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->actor = User::factory()->create();
});

/** A run whose period ends inside the open accounting period. */
function runForLedger(string $end = '2026-08-31'): PayrollRun
{
    $period = PayPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => $end,
    ]);

    return PayrollRun::factory()->create([
        'pay_period_id' => $period->id,
        'status' => PayrollRun::STATUS_COMPUTED,
    ]);
}

/**
 * A payslip carrying a realistic spread: earnings, statutory employee
 * deductions, and an employer contribution that costs and owes at once.
 */
function payslipWithLines(PayrollRun $run, int $gross = 4_500_000): Payslip
{
    $sssEmployee = 90_000;
    $tax = 310_000;
    $sssEmployer = 190_000;
    $deductions = $sssEmployee + $tax;

    return Payslip::factory()->create([
        'payroll_run_id' => $run->id,
        'gross_pay_centavos' => $gross,
        'total_employee_deductions_centavos' => $deductions,
        'total_employer_contributions_centavos' => $sssEmployer,
        'net_pay_centavos' => $gross - $deductions,
        'audit_lines' => [
            ['code' => PayrollLineItem::CODE_BASIC_PAY, 'label' => 'Basic pay', 'amount' => $gross, 'bucket' => PayrollLineItem::BUCKET_EARNING, 'meta' => null],
            ['code' => 'SSS_EMPLOYEE', 'label' => 'SSS (employee)', 'amount' => $sssEmployee, 'bucket' => PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION, 'meta' => null],
            ['code' => 'BIR_WITHHOLDING', 'label' => 'Withholding tax', 'amount' => $tax, 'bucket' => PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION, 'meta' => null],
            ['code' => 'SSS_EMPLOYER', 'label' => 'SSS (employer)', 'amount' => $sssEmployer, 'bucket' => PayrollLineItem::BUCKET_EMPLOYER_CONTRIBUTION, 'meta' => null],
        ],
    ]);
}

function ledger(): LedgerPostingService
{
    return app(LedgerPostingService::class);
}

function postRun(PayrollRun $run): PayrollRun
{
    return app(PostPayrollRunAction::class)->execute($run, (int) test()->actor->getKey());
}

/* ── The balance property ───────────────────────────────────────────── */

it('posts a payroll run to the ledger as one balanced entry', function () {
    $run = runForLedger();
    payslipWithLines($run);

    $posted = postRun($run);
    $entry = $posted->journalEntry;

    expect($entry)->not()->toBeNull()
        ->and($entry->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->isBalanced())->toBeTrue()
        ->and($entry->source_type)->toBe(PayrollRun::class)
        ->and($entry->source_id)->toBe($run->id);
});

it('balances across many payslips', function () {
    $run = runForLedger();

    // Different salaries so the totals are not accidentally symmetric.
    payslipWithLines($run, 4_500_000);
    payslipWithLines($run, 3_275_000);
    payslipWithLines($run, 9_100_050);

    $entry = postRun($run)->journalEntry;

    expect($entry->isBalanced())->toBeTrue()
        ->and($entry->total_debit_centavos)->toBeGreaterThan(0);
});

it('debits earnings and employer cost, credits payables and net pay', function () {
    $run = runForLedger();
    payslipWithLines($run);

    $entry = postRun($run)->journalEntry->load('lines.account');
    $byCode = $entry->lines->keyBy(fn (JournalEntryLine $l) => $l->account->code);

    // 5100 Salaries — the gross earned.
    expect($byCode['5100']->debit_centavos)->toBe(4_500_000);
    // 5110 SSS employer share — a cost to the school.
    expect($byCode['5110']->debit_centavos)->toBe(190_000);
    // 2310 SSS payable — employee 90,000 + employer 190,000.
    expect($byCode['2310']->credit_centavos)->toBe(280_000);
    // 2340 Withholding tax payable.
    expect($byCode['2340']->credit_centavos)->toBe(310_000);
    // 2300 Payroll clearing — net owed to the employee.
    expect($byCode['2300']->credit_centavos)->toBe(4_100_000);
});

it('aggregates per account rather than one line per employee', function () {
    $run = runForLedger();
    payslipWithLines($run);
    payslipWithLines($run);
    payslipWithLines($run);

    $entry = postRun($run)->journalEntry;

    // Five accounts touched regardless of headcount — three employees would
    // otherwise be fifteen lines saying the same five things.
    expect($entry->lines()->count())->toBe(5);
});

it('never emits a negative amount even if a component nets against itself', function () {
    // unpaid_days credits back the salaries expense it was debited into, so
    // 5100 nets down rather than producing a negative debit — which the
    // posting action would refuse outright.
    $run = runForLedger();

    Payslip::factory()->create([
        'payroll_run_id' => $run->id,
        'gross_pay_centavos' => 4_500_000,
        'total_employee_deductions_centavos' => 500_000,
        'total_employer_contributions_centavos' => 0,
        'net_pay_centavos' => 4_000_000,
        'audit_lines' => [
            ['code' => PayrollLineItem::CODE_BASIC_PAY, 'label' => 'Basic pay', 'amount' => 4_500_000, 'bucket' => PayrollLineItem::BUCKET_EARNING, 'meta' => null],
            ['code' => PayrollLineItem::CODE_UNPAID_DAYS, 'label' => 'Unpaid days', 'amount' => 500_000, 'bucket' => PayrollLineItem::BUCKET_EMPLOYEE_DEDUCTION, 'meta' => null],
        ],
    ]);

    $entry = postRun($run)->journalEntry->load('lines.account');
    $salaries = $entry->lines->firstWhere(fn (JournalEntryLine $l) => $l->account->code === '5100');

    expect($salaries->debit_centavos)->toBe(4_000_000)
        ->and($salaries->credit_centavos)->toBe(0)
        ->and($entry->isBalanced())->toBeTrue();

    foreach ($entry->lines as $line) {
        expect($line->debit_centavos)->toBeGreaterThanOrEqual(0)
            ->and($line->credit_centavos)->toBeGreaterThanOrEqual(0);
    }
});

/* ── Idempotency ────────────────────────────────────────────────────── */

it('does not double-post a run that already reached the ledger', function () {
    $run = runForLedger();
    payslipWithLines($run);

    $posted = postRun($run);
    $first = $posted->journalEntry;

    // A retried job or a double-clicked button must not double the expense
    // and the liability.
    $second = ledger()->post($posted->fresh(), (int) $this->actor->getKey());

    expect($second->getKey())->toBe($first->getKey())
        ->and(JournalEntry::query()->count())->toBe(1);
});

/* ── Payroll is not blocked on the books ────────────────────────────── */

it('still posts payroll when the accounting period is closed', function () {
    AccountingPeriod::query()->update([
        'status' => AccountingPeriod::STATUS_CLOSED,
        'closed_at' => now(),
    ]);

    $run = runForLedger();
    payslipWithLines($run);

    $posted = postRun($run);

    // Paying people is not blocked on an accountant having reopened the
    // month. The run posts; the ledger attempt is logged and left for retry.
    expect($posted->status)->toBe(PayrollRun::STATUS_POSTED)
        ->and($posted->hasReachedLedger())->toBeFalse()
        ->and(JournalEntry::query()->count())->toBe(0);
});

it('reaches the ledger on retry once the period reopens', function () {
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    $run = runForLedger();
    payslipWithLines($run);
    $posted = postRun($run);
    expect($posted->hasReachedLedger())->toBeFalse();

    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_OPEN]);

    $entry = ledger()->post($posted->fresh(), (int) $this->actor->getKey());

    expect($entry->isBalanced())->toBeTrue()
        ->and($posted->fresh()->hasReachedLedger())->toBeTrue();
});

it('surfaces the closed period when the ledger is posted directly', function () {
    // Called on its own rather than through the payroll action, the refusal
    // is the caller's to handle — it is only swallowed to protect payroll.
    AccountingPeriod::query()->update(['status' => AccountingPeriod::STATUS_CLOSED]);

    $run = runForLedger();
    payslipWithLines($run);
    $run->forceFill(['status' => PayrollRun::STATUS_POSTED])->save();

    expect(fn () => ledger()->post($run->fresh(), (int) $this->actor->getKey()))
        ->toThrow(ClosedAccountingPeriodException::class);
});

/* ── Guards ─────────────────────────────────────────────────────────── */

it('refuses to post a run that has no payslips', function () {
    $run = runForLedger();
    $run->forceFill(['status' => PayrollRun::STATUS_POSTED])->save();

    expect(fn () => ledger()->post($run->fresh(), (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'no payslips');
});

it('refuses to post a run that has not itself been posted', function () {
    $run = runForLedger();
    payslipWithLines($run);

    expect(fn () => ledger()->post($run, (int) $this->actor->getKey()))
        ->toThrow(DomainException::class, 'Expected [posted]');
});

it('names the missing account when the chart lacks a mapped code', function () {
    $run = runForLedger();
    payslipWithLines($run);
    $run->forceFill(['status' => PayrollRun::STATUS_POSTED])->save();

    // Remove the account the salaries mapping points at.
    ChartOfAccount::query()->where('code', '5100')->delete();

    expect(fn () => ledger()->post($run->fresh(), (int) $this->actor->getKey()))
        ->toThrow(RuntimeException::class, "'5100'");
});

/* ── The frozen payload ─────────────────────────────────────────────── */

it('freezes the breakdown on the run', function () {
    $run = runForLedger();
    payslipWithLines($run);

    $payload = postRun($run)->posting_payload;

    expect($payload)->toBeArray()
        ->and($payload['payroll_run_id'])->toBe($run->id)
        ->and($payload['total_debit_centavos'])->toBe($payload['total_credit_centavos'])
        ->and($payload['lines'])->toHaveCount(5);

    // The payload records what THIS run computed, independent of whatever
    // later happens to the entry.
    $codes = array_column($payload['lines'], 'account_code');
    expect($codes)->toContain('5100', '2300', '2340');
});

it('posts on the pay period end date, not today', function () {
    // The cost belongs to the period the work was done in, which is where an
    // accountant closing the month expects to find it.
    $run = runForLedger('2026-08-31');
    payslipWithLines($run);

    $entry = postRun($run)->journalEntry;

    expect($entry->date->toDateString())->toBe('2026-08-31');
});
