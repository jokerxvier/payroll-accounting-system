/**
 * Dashboard page contract — mirrors `App\Services\Dashboard\DashboardData`.
 *
 * The shape is the handoff between the Inertia controller and the React page.
 * Any change here must land in lockstep with `app/Services/Dashboard/DashboardData.php`.
 *
 * Money is shipped as raw integer centavos so the `<Money>` component can
 * render via `centavos / 100` — matching the convention used elsewhere
 * (employee index, payroll-run show, etc.).
 */

export type PayrollRoleSlug =
    | 'super-admin'
    | 'payroll-officer'
    | 'hr'
    | 'auditor'
    | 'employee';

export type ReminderKind =
    | 'period-close'
    | 'contribution-table-change'
    | 'loan-payoff';

export interface DashboardKpis {
    activeEmployees: { count: number; deltaThisMonth: number };
    currentPeriod: {
        id: number;
        label: string; // e.g. "May 2026"
        startDate: string; // YYYY-MM-DD
        endDate: string; // YYYY-MM-DD
        status: 'draft' | 'open' | 'closed';
    } | null;
    latestRun: {
        id: number;
        periodLabel: string;
        status: string; // PayrollRunStatus from payroll-run.ts
        netPayCentavos: number;
        computedAt: string | null; // ISO-8601 datetime
    } | null;
}

export interface DashboardCta {
    label: string;
    href: string;
}

export interface DashboardActions {
    role: PayrollRoleSlug;
    primaryCta: DashboardCta;
    secondaryCtas: DashboardCta[];
}

export interface DashboardRecentRun {
    id: number;
    periodLabel: string;
    status: string;
    employeeCount: number;
    netTotalCentavos: number;
    computedAt: string | null;
}

export interface DashboardRecentActivity {
    id: number;
    actor: { name: string; initials: string };
    action: string; // 'updated', 'voided', 'approved', etc.
    targetLabel: string; // 'PayrollRun #42'
    occurredAt: string; // ISO-8601 datetime; '' when missing
}

export interface DashboardReminder {
    kind: ReminderKind;
    message: string;
    href: string;
}

export interface DashboardPageProps {
    kpis: DashboardKpis;
    actions: DashboardActions;
    recentRuns: DashboardRecentRun[];
    recentActivity: DashboardRecentActivity[];
    reminders: DashboardReminder[];
}
