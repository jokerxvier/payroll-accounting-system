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
export type StatutoryContributionCode =
    | 'BIR_WITHHOLDING'
    | 'SSS'
    | 'PHILHEALTH'
    | 'PAGIBIG';

export type StatutoryContributionAlgorithm =
    | 'bracket_table'
    | 'salary_band'
    | 'percentage_with_cap'
    | 'tiered_percentage';

export interface StatutoryContributionRow {
    id: number;
    contribution_code: StatutoryContributionCode | string;
    label: string;
    algorithm: StatutoryContributionAlgorithm | string;
    /** ISO `YYYY-MM-DD` (controller cast: `immutable_date`). */
    effective_from: string;
    /** ISO `YYYY-MM-DD` or null when the row is open-ended (currently active). */
    effective_to: string | null;
    rules: Record<string, unknown>;
    notes: string | null;
    created_at: string | null;
    updated_at: string | null;
}

/**
 * `grouped` prop arrives keyed by contribution_code. Some codes may be
 * absent when no rows exist for them yet.
 */
export type StatutoryContributionGrouped = Partial<
    Record<StatutoryContributionCode, StatutoryContributionRow[]>
> &
    Record<string, StatutoryContributionRow[] | undefined>;

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
