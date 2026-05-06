export type PayrollRunStatus =
    | 'draft'
    | 'computing'
    | 'computed'
    | 'pending_approval'
    | 'approved'
    | 'posted'
    | 'voided';

export interface PayPeriodSummary {
    id: number;
    code: string;
    frequency: 'monthly' | 'semi_monthly';
    start_date: string;
    end_date: string;
}

export interface PayrollRunActor {
    id: number;
    name: string;
}

export interface PayrollRunSummary {
    id: number;
    status: PayrollRunStatus;
    is_locked: boolean;
    total_employees: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    total_net_pay_centavos: number;
    started_at: string | null;
    computed_at: string | null;
    submitted_at: string | null;
    approved_at: string | null;
    posted_at: string | null;
    voided_at: string | null;
    created_at: string | null;
    pay_period: PayPeriodSummary | null;
    submitted_by: PayrollRunActor | null;
    approved_by: PayrollRunActor | null;
    posted_by: PayrollRunActor | null;
    voided_by: PayrollRunActor | null;
    bulk_pdf_built_at: string | null;
    has_bulk_pdf: boolean;
}

export interface PayrollRunCanFlags {
    submit: boolean;
    approve: boolean;
    post: boolean;
    void: boolean;
}

export interface PayslipAuditLine {
    code: string;
    label: string;
    amount: number; // centavos
    bucket: 'earning' | 'employee_deduction' | 'employer_contribution';
    meta: Record<string, unknown> | null;
}

export interface PayslipSummary {
    id: number;
    lms_staff_id: number;
    staff_name: string | null;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    net_pay_centavos: number;
    taxable_income_centavos: number;
    applied_exemptions: string[];
    audit_lines: PayslipAuditLine[];
}

export interface PayrollRunProgress {
    persisted_payslips: number;
    total_employees: number;
}

export const PAYROLL_RUN_STATUS_LABELS: Record<PayrollRunStatus, string> = {
    draft: 'Draft',
    computing: 'Computing',
    computed: 'Computed',
    pending_approval: 'Pending approval',
    approved: 'Approved',
    posted: 'Posted',
    voided: 'Voided',
};
