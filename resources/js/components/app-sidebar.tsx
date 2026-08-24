import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    BookText,
    Building2,
    Calculator,
    CalendarDays,
    CalendarRange,
    Check,
    ChevronsUpDown,
    FileSearch,
    LayoutGrid,
    MinusCircle,
    Percent,
    PlayCircle,
    PlusCircle,
    RotateCcw,
    ShieldCheck,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { dashboard } from '@/routes';
import { index as adminAccountingPeriodsIndex } from '@/routes/admin/accounting-periods';
import { index as adminAllowancesIndex } from '@/routes/admin/allowances';
import { index as adminChartOfAccountsIndex } from '@/routes/admin/chart-of-accounts';
import { index as adminContributionTablesIndex } from '@/routes/admin/contribution-tables';
import { index as adminDeductionTypesIndex } from '@/routes/admin/deduction-types';
import { index as adminJournalEntriesIndex } from '@/routes/admin/journal-entries';
import { index as adminPayPeriodsIndex } from '@/routes/admin/pay-periods';
import { index as adminPayrollRunsIndex } from '@/routes/admin/payroll-runs';
import {
    index as adminSchoolsIndex,
    switchMethod as adminSchoolsSwitch,
} from '@/routes/admin/schools';
import { clear as adminSchoolsSwitchClear } from '@/routes/admin/schools/switch';
import { index as adminTaxRatesIndex } from '@/routes/admin/tax-rates';
import { index as employeesIndex } from '@/routes/employees';
import { show as payrollPreviewShow } from '@/routes/payroll/preview';
import type { NavItem } from '@/types';

/**
 * Sidebar visibility constants — each one mirrors the role list in a
 * specific backend policy or controller gate. Keep these in sync with the
 * source of truth on every PR. Per CLAUDE.md "Sidebar gating must mirror
 * the page's authorization": the visible-to-role set must equal the
 * page's policy/gate set.
 */

// Mirrors EmployeeProfilePolicy::viewAny() + the global Gate::before
// in AuthServiceProvider that short-circuits every policy for
// `platform-admin` (the cross-tenant operator role). Platform admins see
// every section within whichever tenant they've switched to.
const EMPLOYEE_DIRECTORY_ROLES = [
    'platform-admin',
    'super-admin',
    'payroll-officer',
    'hr',
    'auditor',
] as const;

// Mirrors PayrollPreviewPolicy::preview() (Gate) and PayrollRunPolicy::viewAny()
// + ReportsController::REPORT_ROLES. The "maker" half of the maker-checker
// payroll workflow. Platform admins included via the Gate::before
// short-circuit.
const PAYROLL_MAKER_ROLES = [
    'platform-admin',
    'super-admin',
    'payroll-officer',
    'hr',
] as const;

// Mirrors viewAny() on AllowancePolicy, DeductionTypePolicy,
// StatutoryContributionPolicy, and PayPeriodPolicy. Catalog read-only for
// makers; catalog mutation is super-admin (gated server-side via `can`).
const CATALOG_READ_ROLES = [
    'platform-admin',
    'super-admin',
    'payroll-officer',
    'hr',
] as const;

// Mirrors App\Policies\Pas\AccountingRoles::VIEW — the role list shared by
// ChartOfAccountPolicy, TaxRatePolicy, and AccountingPeriodPolicy. Read
// access is the union; mutation and the narrower period close/reopen are
// gated server-side and surfaced per row via `can` flags. `payroll-officer`
// is present because config/payroll.php maps the LMS "Accountant" role to it
// (see the AccountingRoles docblock). Platform admins included via the
// Gate::before short-circuit.
const ACCOUNTING_ROLES = [
    'platform-admin',
    'super-admin',
    'accountant',
    'payroll-officer',
    'auditor',
] as const;

// Mirrors AuditLogController::ALLOWED_ROLES.
const AUDIT_ROLES = ['platform-admin', 'super-admin', 'auditor'] as const;

// Mirrors app/Policies/Pas/SchoolPolicy.php — platform-admin only
// (payroll-native users with no LMS counterpart). The role is gated by
// lms_user_id IS NULL on the backend; the frontend trusts auth.user.roles
// because the prop is computed server-side in HandleInertiaRequests, where
// the same lms_user_id IS NULL check is applied before the role list ships.
const SCHOOLS_ADMIN_ROLES = ['platform-admin'] as const;

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
];

const employeeNavItems: NavItem[] = [
    {
        title: 'Employees',
        href: employeesIndex(),
        icon: Users,
    },
];

const payrollNavItems: NavItem[] = [
    {
        title: 'Preview',
        href: payrollPreviewShow(),
        icon: Calculator,
    },
    {
        title: 'Payroll runs',
        href: adminPayrollRunsIndex(),
        icon: PlayCircle,
    },
    {
        title: 'Payroll summary',
        href: '/admin/reports/payroll-summary',
        icon: BarChart3,
    },
    {
        title: 'Employee history',
        href: '/admin/reports/employee-history',
        icon: Users,
    },
];

const catalogNavItems: NavItem[] = [
    {
        title: 'Contribution tables',
        href: adminContributionTablesIndex(),
        icon: ShieldCheck,
    },
    {
        title: 'Deduction types',
        href: adminDeductionTypesIndex(),
        icon: MinusCircle,
    },
    {
        title: 'Allowances',
        href: adminAllowancesIndex(),
        icon: PlusCircle,
    },
    {
        title: 'Pay periods',
        href: adminPayPeriodsIndex(),
        icon: CalendarDays,
    },
];

const accountingNavItems: NavItem[] = [
    {
        title: 'Chart of accounts',
        href: adminChartOfAccountsIndex(),
        icon: BookOpen,
    },
    {
        title: 'Journal',
        href: adminJournalEntriesIndex(),
        icon: BookText,
    },
    {
        title: 'Tax rates',
        href: adminTaxRatesIndex(),
        icon: Percent,
    },
    {
        title: 'Periods',
        href: adminAccountingPeriodsIndex(),
        icon: CalendarRange,
    },
];

const auditNavItems: NavItem[] = [
    {
        title: 'Audit log',
        href: '/admin/audit-logs',
        icon: FileSearch,
    },
];

const schoolsAdminNavItems: NavItem[] = [
    {
        title: 'Schools',
        href: adminSchoolsIndex(),
        icon: Building2,
    },
];

const footerNavItems: NavItem[] = [];

function hasAnyRole(
    userRoles: readonly string[],
    allowed: readonly string[],
): boolean {
    return allowed.some((role) => userRoles.includes(role));
}

export function AppSidebar() {
    const {
        auth,
        currentTenant,
        availableTenants,
        tenantOverrideActive,
        sidebarHiddenSections,
    } = usePage().props;
    const userRoles = auth.user?.roles ?? [];

    // Config-driven presentational hide for sidebar nav groups (demos, etc).
    // Sourced from PAYROLL_SIDEBAR_HIDDEN_SECTIONS via config/payroll.php.
    // Backend authorisation is unchanged — direct URLs still resolve.
    const isHidden = (section: string): boolean =>
        sidebarHiddenSections.includes(section);

    const canViewEmployees =
        hasAnyRole(userRoles, EMPLOYEE_DIRECTORY_ROLES) &&
        !isHidden('directory');
    const canViewPayroll =
        hasAnyRole(userRoles, PAYROLL_MAKER_ROLES) && !isHidden('payroll');
    const canViewCatalog =
        hasAnyRole(userRoles, CATALOG_READ_ROLES) && !isHidden('catalog');
    const canViewAccounting =
        hasAnyRole(userRoles, ACCOUNTING_ROLES) && !isHidden('accounting');
    const canViewAudit =
        hasAnyRole(userRoles, AUDIT_ROLES) && !isHidden('audit');
    const canManageSchools =
        hasAnyRole(userRoles, SCHOOLS_ADMIN_ROLES) && !isHidden('tenants');
    const canSwitchTenant = availableTenants.length > 1;

    const handleSwitchTo = (schoolId: number): void => {
        router.post(
            adminSchoolsSwitch({ school: schoolId }).url,
            {},
            { preserveScroll: false, preserveState: false },
        );
    };

    const handleClearOverride = (): void => {
        router.post(
            adminSchoolsSwitchClear().url,
            {},
            { preserveScroll: false, preserveState: false },
        );
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                {currentTenant ? (
                    canSwitchTenant ? (
                        <DropdownMenu>
                            <DropdownMenuTrigger
                                className="mx-2 mt-1 flex items-center justify-between gap-1.5 rounded-md border border-sidebar-border/60 bg-sidebar-accent/40 px-2 py-1 text-xs group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent"
                                title={`Active tenant: ${currentTenant.name} (${currentTenant.slug}). Click to switch.`}
                            >
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <Building2
                                        className="h-3 w-3 flex-shrink-0 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    <span className="truncate font-medium text-sidebar-foreground">
                                        {currentTenant.name}
                                    </span>
                                    {tenantOverrideActive ? (
                                        <span className="ml-1 rounded bg-amber-100 px-1 text-[9px] font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-200">
                                            PINNED
                                        </span>
                                    ) : null}
                                </span>
                                <ChevronsUpDown
                                    className="h-3 w-3 flex-shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="start" className="w-64">
                                <DropdownMenuLabel>
                                    Switch tenant
                                </DropdownMenuLabel>
                                <DropdownMenuSeparator />
                                {availableTenants.map((tenant) => (
                                    <DropdownMenuItem
                                        key={tenant.id}
                                        onClick={() =>
                                            handleSwitchTo(tenant.id)
                                        }
                                        className="flex items-center justify-between gap-2"
                                    >
                                        <span className="flex flex-col gap-0.5">
                                            <span className="text-sm font-medium">
                                                {tenant.name}
                                            </span>
                                            <span className="font-mono text-[10px] text-muted-foreground">
                                                {tenant.slug}
                                            </span>
                                        </span>
                                        {tenant.id === currentTenant.id ? (
                                            <Check
                                                className="h-4 w-4 text-primary"
                                                aria-hidden="true"
                                            />
                                        ) : null}
                                    </DropdownMenuItem>
                                ))}
                                {tenantOverrideActive ? (
                                    <>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            onClick={handleClearOverride}
                                            className="text-muted-foreground"
                                        >
                                            <RotateCcw
                                                className="mr-2 h-3 w-3"
                                                aria-hidden="true"
                                            />
                                            Clear override
                                        </DropdownMenuItem>
                                    </>
                                ) : null}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    ) : (
                        <div
                            className="mx-2 mt-1 flex items-center gap-1.5 rounded-md border border-sidebar-border/60 bg-sidebar-accent/40 px-2 py-1 text-xs group-data-[collapsible=icon]:hidden"
                            title={`Active tenant: ${currentTenant.name} (${currentTenant.slug})`}
                        >
                            <Building2
                                className="h-3 w-3 flex-shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <span className="truncate font-medium text-sidebar-foreground">
                                {currentTenant.name}
                            </span>
                        </div>
                    )
                ) : null}
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
                {canViewEmployees && (
                    <SidebarSection
                        label="Directory"
                        items={employeeNavItems}
                    />
                )}
                {canViewPayroll && (
                    <SidebarSection label="Payroll" items={payrollNavItems} />
                )}
                {canViewCatalog && (
                    <SidebarSection label="Catalog" items={catalogNavItems} />
                )}
                {canViewAccounting && (
                    <SidebarSection
                        label="Accounting"
                        items={accountingNavItems}
                    />
                )}
                {canViewAudit && (
                    <SidebarSection label="Audit" items={auditNavItems} />
                )}
                {canManageSchools && (
                    <SidebarSection
                        label="Tenants"
                        items={schoolsAdminNavItems}
                    />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

function SidebarSection({ label, items }: { label: string; items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={isCurrentUrl(item.href)}
                            tooltip={{ children: item.title }}
                        >
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <span>{item.title}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}
