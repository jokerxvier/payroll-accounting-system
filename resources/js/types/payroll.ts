/**
 * Inertia prop shapes for Phase 2 / Week 7 — custom deductions, allowances,
 * loans, and one-off payroll adjustments.
 *
 * Field names mirror the Eloquent model `toArray()` output, which in turn
 * mirrors the `pas_*` migration columns shipped in commit 4743fc6:
 *   - pas_deduction_types
 *   - pas_employee_deductions
 *   - pas_allowances
 *   - pas_employee_allowances
 *   - pas_employee_loans
 *   - pas_payroll_adjustments
 *
 * Money is integer centavos. Dates are ISO `YYYY-MM-DD` strings (Eloquent
 * casts `date` columns to immutable dates which serialize to ISO).
 */

export type DeductionCalcMethod = 'fixed' | 'percent';

export type DeductionSource = 'employee' | 'employer' | 'both';

export type SubscriptionSchedule =
    | 'every_run'
    | 'first_half'
    | 'second_half'
    | 'monthly_first'
    | 'monthly_last';

export type PayrollAdjustmentKind = 'addition' | 'deduction';

/** Catalog reference embedded inside an `EmployeeDeductionRow`. */
export interface DeductionTypeRef {
    id: number;
    code: string;
    name: string;
    is_taxable: boolean;
    calc_method: DeductionCalcMethod;
    source: DeductionSource;
}

/**
 * Full `pas_deduction_types` row. Superset of `DeductionTypeRef` — adds the
 * admin-only fields (`is_active`, `notes`) that the catalog index/edit pages
 * surface but per-employee subscription rows don't carry.
 */
export interface DeductionTypeRow extends DeductionTypeRef {
    is_active: boolean;
    notes: string | null;
}

/** Catalog reference embedded inside an `EmployeeAllowanceRow`. */
export interface AllowanceRef {
    id: number;
    code: string;
    name: string;
    is_taxable: boolean;
    is_de_minimis: boolean;
    /** Null cap = no de-minimis limit; defer to BIR rules at compute time. */
    de_minimis_cap_centavos: number | null;
}

/**
 * Full `pas_allowances` row. Superset of `AllowanceRef` — adds the
 * admin-only fields (`default_amount_centavos`, `is_active`, `notes`).
 */
export interface AllowanceRow extends AllowanceRef {
    /** Hint amount surfaced when creating new EmployeeAllowance subscriptions. */
    default_amount_centavos: number | null;
    is_active: boolean;
    notes: string | null;
}

/**
 * Per-employee subscription to a custom deduction type.
 *
 * Exactly one of `amount_centavos` (fixed) or `percent_basis_points` (percent)
 * is populated, matched against `deduction_type.calc_method`. Basis points:
 * 250 = 2.50%, 1_000 = 10.00%.
 */
export interface EmployeeDeductionRow {
    id: number;
    employee_profile_id: number;
    deduction_type: DeductionTypeRef;
    amount_centavos: number | null;
    percent_basis_points: number | null;
    schedule: SubscriptionSchedule;
    /** ISO date `YYYY-MM-DD`. */
    effective_from: string;
    /** ISO date or null (open-ended). */
    effective_to: string | null;
    notes: string | null;
}

/** Per-employee subscription to an allowance. */
export interface EmployeeAllowanceRow {
    id: number;
    employee_profile_id: number;
    allowance: AllowanceRef;
    amount_centavos: number;
    schedule: SubscriptionSchedule;
    effective_from: string;
    effective_to: string | null;
    notes: string | null;
}

/**
 * Lightweight loan ledger header. No interest schedule or amortization rows
 * — Phase 2 only tracks the running outstanding balance.
 */
export interface EmployeeLoanRow {
    id: number;
    employee_profile_id: number;
    code: string;
    principal_centavos: number;
    outstanding_balance_centavos: number;
    monthly_amortization_centavos: number;
    schedule: SubscriptionSchedule;
    started_on: string;
    /** Null while the loan is open; set when the balance hits zero. */
    closed_on: string | null;
    notes: string | null;
}

/** One-off bonus, single deduction, or back-pay correction. */
export interface PayrollAdjustmentRow {
    id: number;
    employee_profile_id: number;
    kind: PayrollAdjustmentKind;
    is_taxable: boolean;
    amount_centavos: number;
    /** Calendar date the adjustment applies to (ISO `YYYY-MM-DD`). */
    applies_on: string;
    label: string;
    reason: string | null;
}
