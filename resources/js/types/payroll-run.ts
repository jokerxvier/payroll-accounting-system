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
    total_employees: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    total_net_pay_centavos: number;
    started_at: string | null;
    computed_at: string | null;
    approved_at: string | null;
    voided_at: string | null;
    created_at: string | null;
    pay_period: PayPeriodSummary | null;
    approved_by: PayrollRunActor | null;
    voided_by: PayrollRunActor | null;
}

export interface PayslipSummary {
    id: number;
    lms_staff_id: number;
    gross_pay_centavos: number;
    total_employee_deductions_centavos: number;
    total_employer_contributions_centavos: number;
    net_pay_centavos: number;
    taxable_income_centavos: number;
    applied_exemptions: string[];
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
