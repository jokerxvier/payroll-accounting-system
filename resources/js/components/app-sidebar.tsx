import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpen,
    BookText,
    Contact as ContactIcon,
    Building2,
    Calculator,
    CalendarDays,
    CalendarClock,
    ChartNoAxesCombined,
    ReceiptText,
    CalendarRange,
    Check,
    ChevronsUpDown,
    FileInput,
    FileSearch,
    FileText,
    CreditCard,
    FileUp,
    LayoutGrid,
    MinusCircle,
    Percent,
    PlayCircle,
    Scale,
    ScrollText,
    PlusCircle,
    RotateCcw,
    ShieldCheck,
    Sigma,
    Users,
    Wallet,
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
import { index as adminContactsIndex } from '@/routes/admin/contacts';
import { index as adminContributionTablesIndex } from '@/routes/admin/contribution-tables';
import { index as adminDeductionTypesIndex } from '@/routes/admin/deduction-types';
import { index as adminInvoicesIndex } from '@/routes/admin/invoices';
import { index as adminJournalEntriesIndex } from '@/routes/admin/journal-entries';
import { index as adminOpeningBalancesIndex } from '@/routes/admin/opening-balances';
import { edit as adminOrganisationEdit } from '@/routes/admin/organisation';
import { index as adminPayPeriodsIndex } from '@/routes/admin/pay-periods';
import { index as adminPaymentGatewaysIndex } from '@/routes/admin/payment-gateways';
import { index as adminPaymentsIndex } from '@/routes/admin/payments';
import { index as adminPayrollRunsIndex } from '@/routes/admin/payroll-runs';
import { index as adminRecurringInvoicesIndex } from '@/routes/admin/recurring-invoices';
import {
    accountingDashboard as adminAccountingDashboard,
    generalLedger as adminGeneralLedgerReport,
    invoiceDashboard as adminInvoiceDashboard,
    journalReport as adminJournalReport,
    trialBalance as adminTrialBalanceReport,
} from '@/routes/admin/reports';
import {
    index as adminSchoolsIndex,
    switchMethod as adminSchoolsSwitch,
} from '@/routes/admin/schools';
import { clear as adminSchoolsSwitchClear } from '@/routes/admin/schools/switch';
import { index as adminTaxRatesIndex } from '@/routes/admin/tax-rates';
import { index as employeesIndex } from '@/routes/employees';
import { show as payrollPreviewShow } from '@/routes/payroll/preview';
import type { NavItem, NavSubGroup } from '@/types';
import type { CurrentTenant } from '@/types/global';

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

// Mirrors App\Policies\Pas\PaymentGatewaySettingPolicy, which reuses
// AccountingRoles::PAYMENT_GATEWAY — the narrowest list in the module. A
// leaked secret key moves money, so this sits with super-admin alone.
const PAYMENT_GATEWAY_ROLES = ['platform-admin', 'super-admin'] as const;

// Mirrors App\Policies\Pas\JournalEntryPolicy::postOpeningBalance, which
// reuses AccountingRoles::POST_LEDGER — narrower than ACCOUNTING_ROLES above,
// which is why Backlog Recording is its own group rather than items inside
// Accounting. Showing it to the wider accounting set would be a dead link:
// `payroll-officer` and `auditor` can read the ledger but cannot post the
// books open.
const LEDGER_POSTING_ROLES = [
    'platform-admin',
    'super-admin',
    'accountant',
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

/**
 * Accounting, in four runs rather than eleven flat links.
 *
 * The grouping is the module's own shape made visible: what you do daily,
 * what those postings land in, what you read back, and what is configured
 * once. A flat list gave no clue which was which.
 *
 * Two items carry their own `roles` because their pages are gated far more
 * narrowly than the rest of Accounting. They used to be separate top-level
 * groups for exactly that reason; folding them in here is only safe because
 * `NavItem.roles` now enforces the same narrowing per item.
 */
const accountingNavGroups: NavSubGroup[] = [
    {
        label: 'Transactions',
        items: [
            // Invoices, bills, payments and contacts all answer to
            // AccountingRoles::VIEW, the same set as the group gate, so none
            // needs its own `roles`.
            {
                title: 'Invoices',
                hideKey: 'accounting.invoices',
                href: adminInvoicesIndex(),
                icon: FileText,
            },
            // Bills share the invoice controller and policy — the `type`
            // filter is what separates them. Its own entry because a screen
            // reachable only by hand-editing a query string is not reachable.
            // Same gate as the group: whoever may see invoices may see the
            // standing instructions that raise them.
            {
                title: 'Recurring invoices',
                hideKey: 'accounting.recurring-invoices',
                href: adminRecurringInvoicesIndex(),
                icon: CalendarClock,
            },
            {
                title: 'Bills',
                hideKey: 'accounting.bills',
                href: adminInvoicesIndex({ query: { type: 'purchase' } }),
                icon: FileInput,
            },
            {
                title: 'Payments',
                hideKey: 'accounting.payments',
                href: adminPaymentsIndex(),
                icon: Wallet,
            },
            {
                title: 'Contacts',
                hideKey: 'accounting.contacts',
                href: adminContactsIndex(),
                icon: ContactIcon,
            },
        ],
    },
    {
        label: 'Books',
        items: [
            {
                title: 'Chart of accounts',
                hideKey: 'accounting.chart-of-accounts',
                href: adminChartOfAccountsIndex(),
                icon: BookOpen,
            },
            {
                title: 'Journal',
                hideKey: 'accounting.journal',
                href: adminJournalEntriesIndex(),
                icon: BookText,
            },
        ],
    },
    {
        label: 'Reports',
        items: [
            // LedgerReportController and FinancialDashboardController both
            // authorize through JournalEntryPolicy::viewAny — reading a report
            // is reading the ledger — which is AccountingRoles::VIEW again.
            {
                title: 'Dashboard',
                hideKey: 'accounting.dashboard',
                href: adminAccountingDashboard(),
                icon: ChartNoAxesCombined,
            },
            {
                title: 'Invoices',
                hideKey: 'accounting.invoice-dashboard',
                href: adminInvoiceDashboard(),
                icon: ReceiptText,
            },
            {
                title: 'Trial balance',
                hideKey: 'accounting.trial-balance',
                href: adminTrialBalanceReport(),
                icon: Scale,
            },
            {
                title: 'General ledger',
                hideKey: 'accounting.general-ledger',
                href: adminGeneralLedgerReport(),
                icon: ScrollText,
            },
            {
                title: 'Journal report',
                hideKey: 'accounting.journal-report',
                href: adminJournalReport(),
                icon: Sigma,
            },
        ],
    },
    {
        label: 'Settings',
        items: [
            // The school's own letterhead. Same gate as payment gateways —
            // both are things a school presents to the outside world.
            {
                title: 'Organisation',
                hideKey: 'accounting.organisation',
                href: adminOrganisationEdit(),
                icon: Building2,
                roles: PAYMENT_GATEWAY_ROLES,
            },
            {
                title: 'Tax rates',
                hideKey: 'accounting.tax-rates',
                href: adminTaxRatesIndex(),
                icon: Percent,
            },
            {
                title: 'Accounting periods',
                hideKey: 'accounting.periods',
                href: adminAccountingPeriodsIndex(),
                icon: CalendarRange,
            },
            // Mirrors JournalEntryPolicy::postOpeningBalance — POST_LEDGER,
            // narrower than the Accounting group gate.
            {
                title: 'Opening balances',
                hideKey: 'accounting.opening-balances',
                href: adminOpeningBalancesIndex(),
                icon: FileUp,
                roles: LEDGER_POSTING_ROLES,
            },
            // Mirrors PaymentGatewaySettingPolicy — the narrowest list in the
            // module, because a leaked secret key moves money.
            {
                title: 'Payment gateways',
                hideKey: 'accounting.payment-gateways',
                href: adminPaymentGatewaysIndex(),
                icon: CreditCard,
                roles: PAYMENT_GATEWAY_ROLES,
            },
        ],
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
                                    <TenantMark tenant={currentTenant} />
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
                            <TenantMark tenant={currentTenant} />
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
                        isHidden={isHidden}
                        userRoles={userRoles}
                    />
                )}
                {canViewPayroll && (
                    <SidebarSection
                        label="Payroll"
                        items={payrollNavItems}
                        isHidden={isHidden}
                        userRoles={userRoles}
                    />
                )}
                {canViewCatalog && (
                    <SidebarSection
                        label="Catalog"
                        items={catalogNavItems}
                        isHidden={isHidden}
                        userRoles={userRoles}
                    />
                )}
                {canViewAccounting && (
                    <SidebarGroupedSection
                        label="Accounting"
                        groups={accountingNavGroups}
                        isHidden={isHidden}
                        userRoles={userRoles}
                    />
                )}
                {canViewAudit && (
                    <SidebarSection
                        label="Audit"
                        items={auditNavItems}
                        isHidden={isHidden}
                        userRoles={userRoles}
                    />
                )}
                {canManageSchools && (
                    <SidebarSection
                        label="Tenants"
                        items={schoolsAdminNavItems}
                        isHidden={isHidden}
                        userRoles={userRoles}
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

/**
 * Items this user may see, minus anything the config hides.
 *
 * Two independent filters, and they answer different questions: `roles` is
 * authorization — showing a link the page will 403 is a dead link — while
 * `hideKey` is presentational, for scoping a demo to a sprint. An item needs
 * `roles` only when its page is gated more narrowly than the group it sits in.
 */
/**
 * The school's own mark, or the generic one.
 *
 * Falls back rather than reserving an empty box: a school with no logo — or an
 * environment where `storage:link` was never run — should look deliberate, not
 * broken.
 */
function TenantMark({ tenant }: { tenant: CurrentTenant }) {
    if (tenant.logo_url) {
        return (
            <img
                src={tenant.logo_url}
                alt=""
                className="h-3.5 w-3.5 flex-shrink-0 rounded-[2px] object-contain"
            />
        );
    }

    return (
        <Building2
            className="h-3 w-3 flex-shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
    );
}

function visibleItems(
    items: NavItem[],
    isHidden: (key: string) => boolean,
    userRoles: string[],
): NavItem[] {
    return items.filter(
        (item) =>
            (item.hideKey === undefined || !isHidden(item.hideKey)) &&
            (item.roles === undefined || hasAnyRole(userRoles, item.roles)),
    );
}

function NavLinks({ items }: { items: NavItem[] }) {
    const { isCurrentUrl } = useCurrentUrl();

    return (
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
    );
}

/**
 * A flat nav group.
 *
 * Renders nothing at all when every item is filtered out — a group label with
 * no links under it reads as a broken menu rather than a deliberate one.
 */
function SidebarSection({
    label,
    items,
    isHidden,
    userRoles,
}: {
    label: string;
    items: NavItem[];
    isHidden: (key: string) => boolean;
    userRoles: string[];
}) {
    const visible = visibleItems(items, isHidden, userRoles);

    if (visible.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            <NavLinks items={visible} />
        </SidebarGroup>
    );
}

/**
 * A nav group with labelled runs inside it.
 *
 * The subheadings are deliberately quieter than the group's own label —
 * subordinate, not competing. An empty run disappears, and so does the whole
 * group when every run is empty, so a narrowly-gated user never meets a
 * heading with nothing beneath it.
 */
function SidebarGroupedSection({
    label,
    groups,
    isHidden,
    userRoles,
}: {
    label: string;
    groups: NavSubGroup[];
    isHidden: (key: string) => boolean;
    userRoles: string[];
}) {
    const populated = groups
        .map((group) => ({
            label: group.label,
            items: visibleItems(group.items, isHidden, userRoles),
        }))
        .filter((group) => group.items.length > 0);

    if (populated.length === 0) {
        return null;
    }

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{label}</SidebarGroupLabel>
            {populated.map((group) => (
                <div key={group.label}>
                    <SidebarGroupLabel className="h-6 px-2 text-[0.6875rem] text-sidebar-foreground/50">
                        {group.label}
                    </SidebarGroupLabel>
                    <NavLinks items={group.items} />
                </div>
            ))}
        </SidebarGroup>
    );
}
