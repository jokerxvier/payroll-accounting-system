<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;

return [

    /*
    |--------------------------------------------------------------------------
    | Payroll → general ledger account mapping
    |--------------------------------------------------------------------------
    |
    | `rules/PLAN.md` §11 required this to be "a configurable lookup, not
    | hardcoded", because the chart of accounts is per-school and the codes
    | below are only the defaults shipped by AccountingCatalogSeeder.
    |
    | Keys are the `code` of a PayrollLineItem emitted by the computation
    | engine. Values are chart-of-accounts `code`s within the posting
    | school. A school that renumbers its chart overrides these; a school
    | that adds a statutory contribution adds the matching keys.
    |
    | Statutory line codes are composed at runtime by the engine from each
    | StatutoryContribution row — `{CODE}_EMPLOYEE`, `{CODE}_EMPLOYER`,
    | `{CODE}_EMPLOYER_EC` — so the four PH contributions below are entries
    | in a lookup, not an exhaustive list. A contribution with no mapping
    | falls through to the bucket default rather than being dropped: losing
    | a line would unbalance the entry, and a wrong-but-visible account is
    | far easier to spot and correct than a missing amount.
    |
    */

    'payroll' => [

        /*
        | Earnings — debited as employer cost.
        */
        'earnings' => [
            'BASIC_PAY' => '5100',
            'allowance_taxable' => '5100',
            'allowance_non_taxable' => '5100',
            'adjustment_addition' => '5100',
            'default' => '5100',
        ],

        /*
        | Employee deductions — withheld from gross, so credited to the
        | liability the school now owes to whoever the money is destined for.
        |
        | `unpaid_days` is an employee_deduction rather than a negative
        | earning (see ApplyUnpaidDays), so it credits back the salaries
        | expense it was debited into. That keeps the expense at what was
        | actually earned without introducing a negative line.
        */
        'employee_deductions' => [
            'SSS_EMPLOYEE' => '2310',
            'PHILHEALTH_EMPLOYEE' => '2320',
            'PAGIBIG_EMPLOYEE' => '2330',
            'BIR_WITHHOLDING' => '2340',
            'unpaid_days' => '5100',
            'loan_amortization' => '2100',
            'custom_deduction' => '2100',
            'adjustment_deduction' => '2100',
            'default' => '2100',
        ],

        /*
        | Employer contributions — a cost to the school AND a liability to
        | the agency, so each posts twice: debit the expense, credit the
        | payable. Both halves are required; a mapping with only one would
        | unbalance the entry.
        */
        'employer_contributions' => [
            'SSS_EMPLOYER' => ['expense' => '5110', 'liability' => '2310'],
            'SSS_EMPLOYER_EC' => ['expense' => '5110', 'liability' => '2310'],
            'PHILHEALTH_EMPLOYER' => ['expense' => '5120', 'liability' => '2320'],
            'PAGIBIG_EMPLOYER' => ['expense' => '5130', 'liability' => '2330'],
            'default' => ['expense' => '5900', 'liability' => '2100'],
        ],

        /*
        | Net pay is credited here rather than straight to cash: posting the
        | payroll run records what is owed to employees, and the cash only
        | moves when the disbursement is recorded. Clearing the balance is
        | what proves the two agree.
        |
        | Resolved by `system_code`, not by account code — the account is
        | locked precisely so this lookup cannot break.
        */
        'net_pay_system_account' => ChartOfAccount::SYSTEM_PAYROLL_CLEARING,

    ],

];
