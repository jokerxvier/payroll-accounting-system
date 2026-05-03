/**
 * Inertia prop shapes for Phase 2 / Week 8 — real-time gross-to-net payroll
 * preview.
 *
 * Source of truth: `App\Services\Payroll\PayrollComputationResult::toArray()`
 * and `App\Services\Payroll\PayrollLineItem::toArray()`. Keep field names and
 * structure aligned with those PHP DTOs — the controller renders both verbatim
 * via `Inertia::render()`.
 *
 * Money fields are surfaced as `{centavos, formatted}` pairs:
 *   - `centavos` is the authoritative integer (avoids float drift).
 *   - `formatted` is a 2-decimal string that the frontend renders via
 *     `<Money>` (which re-formats to the locale-aware peso symbol).
 *
 * The frontend never multiplies / divides amounts — it presents `centavos / 100`
 * to `<Money>` and lets the component handle locale formatting.
 */

/**
 * Wire shape for a `Money` value. Matches the PHP serializer in
 * `PayrollComputationResult::serializeMoney()` and `PayrollLineItem::toArray()`.
 */
export interface MoneyDTO {
    centavos: number;
    formatted: string;
}

/**
 * The three pay-period frequencies the preview screen exposes. Mirrors the
 * `PayPeriodInput` factory methods (`monthly`, `semiMonthlyFirst`,
 * `semiMonthlySecond`).
 */
export type PreviewFrequency =
    | 'monthly'
    | 'semi_monthly_first'
    | 'semi_monthly_second';

/**
 * Bucket assigned to each `PayrollLineItem`. The breakdown card groups lines
 * by this value:
 *   - `earning`              → adds to gross pay (basic, allowances, OT…).
 *   - `employee_deduction`   → subtracted from gross to arrive at net.
 *   - `employer_contribution`→ paid alongside payroll; informational only,
 *                              never affects net.
 */
export type AuditLineBucket =
    | 'earning'
    | 'employee_deduction'
    | 'employer_contribution';

/**
 * Single audit line emitted by the engine. The engine guarantees one item per
 * discrete contribution (basic pay, SSS employee share, BIR withholding, …),
 * already in canonical display order.
 *
 * `meta` is opaque — only the renderer / payslip view consumes it. Per the
 * controller contract it carries strategy-specific diagnostics (the SSS
 * bracket id, the BIR bracket label, the PhilHealth ceiling that was applied,
 * …); do not assume any particular shape across line types.
 *
 * The `code` field carries the canonical `PayrollLineItem::CODE_*` constants
 * (e.g. `BASIC_PAY`, `SSS_EMPLOYEE`, `allowance_taxable`, `unpaid_days`).
 */
export interface AuditLineDTO {
    code: string;
    label: string;
    amount: MoneyDTO;
    bucket: AuditLineBucket;
    meta: Record<string, unknown> | null;
}

/**
 * Period envelope returned alongside every `PayrollComputationResultDTO`.
 * Sufficient for the breakdown header to render "May 1 – May 31, 2026
 * (Monthly)" without re-deriving anything client-side.
 */
export interface PeriodDTO {
    frequency: PreviewFrequency;
    /** ISO `YYYY-MM-DD` (start of the cutoff window). */
    start: string;
    /** ISO `YYYY-MM-DD` (end of the cutoff window). */
    end: string;
    daysInPeriod: number;
}

/**
 * Full computation result for ONE employee against ONE period.
 *
 * Aggregate Money fields are convenience views; the authoritative breakdown
 * is `auditLines`. Totals are derived from lines, not the other way around —
 * the engine owns the reconciliation.
 *
 * Field set is locked to `PayrollComputationResult::toArray()`. Adding a
 * field on the PHP side requires extending this interface in the same diff.
 */
export interface PayrollComputationResultDTO {
    period: PeriodDTO;
    basicPay: MoneyDTO;
    grossPay: MoneyDTO;
    sssEmployee: MoneyDTO;
    sssEmployer: MoneyDTO;
    sssEmployerEc: MoneyDTO;
    philhealthEmployee: MoneyDTO;
    philhealthEmployer: MoneyDTO;
    pagibigEmployee: MoneyDTO;
    pagibigEmployer: MoneyDTO;
    birWithholdingTax: MoneyDTO;
    totalEmployeeDeductions: MoneyDTO;
    totalEmployerContributions: MoneyDTO;
    taxableIncome: MoneyDTO;
    netPay: MoneyDTO;
    allowancesTaxable: MoneyDTO;
    allowancesNonTaxable: MoneyDTO;
    customDeductionsEmployee: MoneyDTO;
    customDeductionsEmployer: MoneyDTO;
    loanDeductions: MoneyDTO;
    adjustmentTaxableAdditions: MoneyDTO;
    adjustmentNonTaxableAdditions: MoneyDTO;
    adjustmentDeductions: MoneyDTO;
    unpaidDaysReduction: MoneyDTO;
    unpaidDaysCount: number;
    auditLines: AuditLineDTO[];
}

/**
 * Compact employee row surfaced in the typeahead dropdown.
 *
 * `has_profile` is always `true` for entries returned by the controller (the
 * loader filters on `is_active = true` over `pas_employee_profiles`). The
 * field is kept on the wire so the UI can reflect the same state if a
 * downstream loader ever surfaces "no profile" candidates.
 */
export interface PreviewEmployeeOption {
    lms_staff_id: number;
    full_name: string;
    employment_type: string | null;
    has_profile: boolean;
}

/**
 * The four selection inputs the compute endpoint requires. Mirrored from
 * `PayrollPreviewComputeRequest` validation rules.
 */
export interface PreviewSelection {
    lms_staff_id: number;
    frequency: PreviewFrequency;
    year: number;
    month: number;
}

/**
 * The `defaultPeriod` block returned by `show()`. Drives the initial control
 * state on first render so the form opens on "this month, monthly".
 */
export interface PreviewDefaultPeriod {
    frequency: PreviewFrequency;
    year: number;
    month: number;
}

/**
 * Full Inertia page-prop envelope for `pages/payroll/preview.tsx`.
 *
 * `selection`, `noProfile`, `currentPeriod`, `priorPeriod`, and `computedAt`
 * are all `null` on first render (the GET `show` action) and overwritten by
 * the POST `compute` action. The page treats `currentPeriod !== null` as the
 * single signal to render the breakdown view.
 */
export interface PreviewPageProps {
    employees: PreviewEmployeeOption[];
    frequencyOptions: Record<PreviewFrequency, string>;
    defaultPeriod: PreviewDefaultPeriod;
    selection: PreviewSelection | null;
    noProfile: boolean | null;
    currentPeriod: PayrollComputationResultDTO | null;
    priorPeriod: PayrollComputationResultDTO | null;
    /** ISO-8601 timestamp of when the engine produced the result. */
    computedAt: string | null;
    // Inertia `usePage<T>()` requires the prop bag to be assignable to its
    // `PageProps` index-signature — the shared `auth`, `name`, and
    // `sidebarOpen` props live alongside ours.
    [key: string]: unknown;
}
