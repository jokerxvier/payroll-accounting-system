/**
 * Shape of a row from `pas_statutory_contributions`. Mirrors the controller's
 * `Inertia::render` payload (the model is sent as-is via `toArray()`, so dates
 * arrive as ISO strings).
 *
 * The `rules` payload is algorithm-specific; see the discriminated union
 * below for the four valid shapes. The Inertia controller does not narrow it
 * for us, so we type it loosely here and let each subform widen-then-narrow
 * when it owns the value.
 */

/**
 * Statutory contribution codes are admin-defined: the five {@see RECOMMENDED_STATUTORY_CODES}
 * are the well-known ones shipped out of the box, but admins can register
 * additional custom codes (e.g. a city-specific tax) that match
 * {@see STATUTORY_CODE_PATTERN}. Treat the type as plain `string` everywhere;
 * use {@link CODE_LABELS} (in the page that owns the lookup) for display
 * names and fall back to the raw code when the lookup misses.
 */
export type StatutoryContributionCode = string;

/**
 * Authoritative regex for a valid contribution code:
 *   - starts with an uppercase letter
 *   - 3–32 chars total
 *   - body may contain uppercase letters, digits, or underscores
 *
 * Mirrors the backend `pas_statutory_contributions.contribution_code` validation.
 */
export const STATUTORY_CODE_PATTERN = /^[A-Z][A-Z0-9_]{2,31}$/;

/**
 * Recommended quick-pick codes shown above the free-text input on the create
 * page. These are the well-known statutory codes. Admins can still type any
 * code that matches {@link STATUTORY_CODE_PATTERN}.
 */
export const RECOMMENDED_STATUTORY_CODES = [
    'BIR_WITHHOLDING',
    'SSS',
    'PHILHEALTH',
    'PAGIBIG',
    'CITY_TAX',
] as const;

export type StatutoryContributionAlgorithm =
    | 'bracket_table'
    | 'salary_band'
    | 'percentage_with_cap'
    | 'tiered_percentage'
    | 'flat_percentage';

export type StatutoryContributionAppliesTo = 'gross_pay' | 'taxable_income';

export interface StatutoryContributionRow {
    id: number;
    contribution_code: StatutoryContributionCode;
    label: string;
    algorithm: StatutoryContributionAlgorithm | string;
    /** ISO `YYYY-MM-DD` (controller cast: `immutable_date`). */
    effective_from: string;
    /** ISO `YYYY-MM-DD` or null when the row is open-ended (currently active). */
    effective_to: string | null;
    rules: Record<string, unknown>;
    notes: string | null;
    /**
     * ISO datetime when the row was retired, or null if still live. Voided
     * rows are hidden from every payroll computation path even when their
     * effective range covers the requested date.
     */
    voided_at: string | null;
    /** Eager-loaded only on the show payload; omitted (undefined) elsewhere. */
    voided_by?: { id: number; name: string } | null;
    /**
     * Computed by the backend (`StatutoryContribution::isEditable()`); true iff
     * the row is strictly future-dated, open-ended, and not voided. The
     * `update` and `void` policy gates already enforce this server-side — the
     * field exists so the client UI can mirror the same gate without
     * re-deriving it. Index, show, and edit payloads all populate it.
     */
    is_editable: boolean;
    /**
     * Application order — present on the model but not always exposed in
     * controller payloads. Optional here so existing callers don't need to
     * include it.
     */
    application_order?: number;
    /** Same — optional because not every payload exposes it. */
    applies_to?: StatutoryContributionAppliesTo;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * `grouped` prop arrives keyed by contribution_code. Codes are admin-defined
 * (any string matching {@link STATUTORY_CODE_PATTERN}); some recommended codes
 * may be absent when no rows exist for them yet.
 */
export type StatutoryContributionGrouped = Record<
    string,
    StatutoryContributionRow[] | undefined
>;

// ---- Algorithm rule shapes (subform output contract) ----

export interface BracketTableBracket {
    lower: number; // centavos
    upper: number | null; // centavos or null = top tier
    base_tax: number; // centavos
    excess_rate_bp: number; // basis points (100 = 1%)
    excess_over: number; // centavos
}

export interface BracketTableRules {
    period_types: Record<string, BracketTableBracket[]>;
}

export interface SalaryBand {
    lower: number; // centavos
    upper: number | null; // centavos or null = top tier
    contribution_base: number; // centavos
    employee_share: number; // centavos
    employer_share: number; // centavos
    employer_aux_share: number; // centavos (e.g. SSS EC)
    employee_aux_share: number; // centavos
}

export interface SalaryBandRules {
    bands: SalaryBand[];
}

export interface PercentageWithCapRules {
    rate_bp: number;
    floor: number;
    ceiling: number;
    split: {
        employee_bp: number;
        employer_bp: number;
    };
}

export interface TieredPercentageRules {
    threshold: number;
    lower: { ee_bp: number; er_bp: number };
    upper: { ee_bp: number; er_bp: number };
    max_msc: number;
}

/**
 * Canonical, persisted shape for the `flat_percentage` algorithm. Stored on
 * the row in basis points (100 = 1%) and centavos. The admin form drives this
 * shape via decimal-percent / decimal-pesos inputs and the controller's
 * `prepareForValidation()` normalises both shapes to the same JSON.
 */
export interface FlatPercentageRules {
    rate_bp: number;
    employee_share_bp: number;
    employer_share_bp: number;
    cap: number | null;
}
