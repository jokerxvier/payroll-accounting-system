<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Accounting\AccountingPeriodController;
use App\Http\Controllers\Admin\Accounting\ChartOfAccountController;
use App\Http\Controllers\Admin\Accounting\ChartOfAccountImportController;
use App\Http\Controllers\Admin\Accounting\ContactController;
use App\Http\Controllers\Admin\Accounting\ContactImportController;
use App\Http\Controllers\Admin\Accounting\FinancialDashboardController;
use App\Http\Controllers\Admin\Accounting\GuardianImportController;
use App\Http\Controllers\Admin\Accounting\InvoiceController;
use App\Http\Controllers\Admin\Accounting\InvoicePrintController;
use App\Http\Controllers\Admin\Accounting\JournalEntryController;
use App\Http\Controllers\Admin\Accounting\LedgerReportController;
use App\Http\Controllers\Admin\Accounting\OpeningBalanceController;
use App\Http\Controllers\Admin\Accounting\OpeningItemController;
use App\Http\Controllers\Admin\Accounting\PaymentController;
use App\Http\Controllers\Admin\Accounting\PaymentGatewaySettingController;
use App\Http\Controllers\Admin\Accounting\ReceivablesDashboardController;
use App\Http\Controllers\Admin\Accounting\RecurringInvoiceController;
use App\Http\Controllers\Admin\Accounting\TaxRateController;
use App\Http\Controllers\Admin\AllowanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DeductionTypeController;
use App\Http\Controllers\Admin\DevSeedController;
use App\Http\Controllers\Admin\EmployeeBulkImportController;
use App\Http\Controllers\Admin\OrganisationController;
use App\Http\Controllers\Admin\PayPeriodController;
use App\Http\Controllers\Admin\PayrollRunController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SchoolController;
use App\Http\Controllers\Admin\StatutoryContributionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function (): void {
        Route::get('contribution-tables', [StatutoryContributionController::class, 'index'])
            ->name('contribution-tables.index');
        Route::get('contribution-tables/create', [StatutoryContributionController::class, 'create'])
            ->name('contribution-tables.create');
        // Phase 3 W12 Stage B — Excel snapshot of every contribution row.
        // Static segment registered BEFORE the {contribution} wildcard so
        // /contribution-tables/template doesn't get caught by route-model
        // binding as a literal id.
        Route::get('contribution-tables/template', [StatutoryContributionController::class, 'template'])
            ->name('contribution-tables.template');
        Route::post('contribution-tables', [StatutoryContributionController::class, 'store'])
            ->name('contribution-tables.store');

        // {contribution} routes are registered AFTER `create` so the static
        // segment wins the match. Order is load-bearing — moving these above
        // `create` would silently route GET /contribution-tables/create to
        // show() with the literal string "create" as the bound model.
        Route::get('contribution-tables/{contribution}', [StatutoryContributionController::class, 'show'])
            ->name('contribution-tables.show');
        Route::get('contribution-tables/{contribution}/edit', [StatutoryContributionController::class, 'edit'])
            ->name('contribution-tables.edit');
        Route::patch('contribution-tables/{contribution}', [StatutoryContributionController::class, 'update'])
            ->name('contribution-tables.update');
        Route::post('contribution-tables/{contribution}/void', [StatutoryContributionController::class, 'void'])
            ->name('contribution-tables.void');

        // Week 7 catalog admin — DeductionType (resource controller minus show
        // since the index doubles as the catalog overview). The kebab-case URI
        // is mapped back to the camelCase `deductionType` route parameter so
        // the controller can keep the conventional variable name without
        // litter from kebab-case in PHP code.
        Route::resource('deduction-types', DeductionTypeController::class)
            ->parameters(['deduction-types' => 'deductionType'])
            ->except(['show']);

        // Week 7 catalog admin — Allowance. Default singular `allowance`
        // parameter is fine; no parameters() override needed.
        Route::resource('allowances', AllowanceController::class)
            ->except(['show']);

        // Phase 3 Week 9 — Pay periods (lightweight CRUD: list + create).
        // Edit/show land later; for now an admin creates a period in `open`
        // status and immediately uses it to generate a payroll run.
        Route::get('pay-periods', [PayPeriodController::class, 'index'])
            ->name('pay-periods.index');
        Route::get('pay-periods/create', [PayPeriodController::class, 'create'])
            ->name('pay-periods.create');
        Route::post('pay-periods', [PayPeriodController::class, 'store'])
            ->name('pay-periods.store');
        Route::patch('pay-periods/{payPeriod}', [PayPeriodController::class, 'update'])
            ->name('pay-periods.update');

        // Phase 3 Week 9 — Payroll runs (batch generate / show / list).
        // Approval + voiding land in Week 10; not exposed yet.
        Route::get('payroll-runs', [PayrollRunController::class, 'index'])
            ->name('payroll-runs.index');
        Route::get('payroll-runs/create', [PayrollRunController::class, 'create'])
            ->name('payroll-runs.create');
        Route::post('payroll-runs', [PayrollRunController::class, 'store'])
            ->name('payroll-runs.store');
        Route::get('payroll-runs/{payrollRun}', [PayrollRunController::class, 'show'])
            ->name('payroll-runs.show');
        // Hard-delete a run (type-to-confirm on the client). Registered as a
        // distinct DELETE verb; the {payrollRun} wildcard above only binds GET,
        // so there's no static-segment collision to guard against here.
        Route::delete('payroll-runs/{payrollRun}', [PayrollRunController::class, 'destroy'])
            ->name('payroll-runs.destroy');

        // Phase 3 Week 10 — approval lifecycle transitions. Each one is a
        // POST that hits a Gate-checked controller action and an Action
        // class with a defensive status-invariant guard.
        Route::post('payroll-runs/{payrollRun}/submit', [PayrollRunController::class, 'submit'])
            ->name('payroll-runs.submit');
        Route::post('payroll-runs/{payrollRun}/approve', [PayrollRunController::class, 'approve'])
            ->name('payroll-runs.approve');
        Route::post('payroll-runs/{payrollRun}/post', [PayrollRunController::class, 'post'])
            ->name('payroll-runs.post');
        Route::post('payroll-runs/{payrollRun}/void', [PayrollRunController::class, 'void'])
            ->name('payroll-runs.void');

        // Phase 3 W11 — single-payslip standalone view (HTML) + sibling
        // .pdf route streaming a dompdf-rendered file (Stage B).
        Route::get('payroll-runs/{payrollRun}/payslips/{payslip}', [PayrollRunController::class, 'showPayslip'])
            ->name('payroll-runs.payslips.show');
        Route::get('payroll-runs/{payrollRun}/payslips/{payslip}/pdf', [PayrollRunController::class, 'downloadPayslipPdf'])
            ->name('payroll-runs.payslips.pdf');

        // Phase 3 W11 Stage C — bulk payslips ZIP. POST kicks off the
        // queued build; GET streams the assembled artefact.
        Route::post('payroll-runs/{payrollRun}/bulk-pdfs', [PayrollRunController::class, 'buildBulkPdfs'])
            ->name('payroll-runs.bulk-pdfs.build');
        Route::get('payroll-runs/{payrollRun}/bulk-pdfs', [PayrollRunController::class, 'downloadBulkPdfs'])
            ->name('payroll-runs.bulk-pdfs.download');

        // Phase 3 W12 Stage A — employee bulk-edit via Excel. Three-step
        // flow: download template → upload+preview → confirm.
        Route::get('employees/import', [EmployeeBulkImportController::class, 'index'])
            ->name('employees.import.index');
        Route::get('employees/import/template', [EmployeeBulkImportController::class, 'template'])
            ->name('employees.import.template');
        Route::post('employees/import/preview', [EmployeeBulkImportController::class, 'preview'])
            ->name('employees.import.preview');
        Route::post('employees/import/confirm/{token}', [EmployeeBulkImportController::class, 'confirm'])
            ->name('employees.import.confirm');

        // Phase 4 W14 — Audit log viewer. Read-only surface for auditors
        // + super-admin. Stage B's sibling /export streams CSV.
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->name('audit-logs.index');
        Route::get('audit-logs/export', [AuditLogController::class, 'export'])
            ->name('audit-logs.export');

        // Phase 4 W13 — Reports module. Read-only aggregates over payslips.
        // Auth widens to super-admin + payroll-officer + hr because reports
        // are an analytical surface needed by HR for monthly review.
        Route::get('reports/payroll-summary', [ReportsController::class, 'payrollSummary'])
            ->name('reports.payroll-summary');
        Route::get('reports/payroll-summary/export', [ReportsController::class, 'payrollSummaryExport'])
            ->name('reports.payroll-summary.export');
        Route::get('reports/employee-history', [ReportsController::class, 'employeeHistory'])
            ->name('reports.employee-history');
        Route::get('reports/employee-history/export', [ReportsController::class, 'employeeHistoryExport'])
            ->name('reports.employee-history.export');

        // Phase B.2 — multi-tenant schools registry. Super-admin only.
        // Static `test-connection` POST is registered BEFORE the resource
        // so the literal segment wins the match against `schools/{school}`.
        // `show` is excluded — the index doubles as the listing surface,
        // mirroring `allowances` / `deduction-types`.
        Route::post('schools/test-connection', [SchoolController::class, 'testConnection'])
            ->name('schools.test-connection');
        // Phase E preview — super-admin tenant switcher. Stores an override id
        // in the session that SchoolTenantFinder reads BEFORE its existing
        // domain/path/header strategies. Static segment registered before the
        // resource so `schools/switch/{school}` doesn't bind to {school}.
        Route::post('schools/switch/{school}', [SchoolController::class, 'switchTenant'])
            ->name('schools.switch');
        Route::post('schools/switch', [SchoolController::class, 'clearSwitch'])
            ->name('schools.switch.clear');
        Route::resource('schools', SchoolController::class)->except(['show']);

        // ── Phase 5 Slice 1 — accounting ledger foundation ──────────────
        //
        // Chart of accounts. `show`, `create`, and `edit` are all excluded:
        // the index is the listing surface, and creating/editing happens in
        // a sheet on that same page (RULES.md §807), so there are no
        // standalone form pages to route to. store / update / destroy are
        // what the sheet posts to.
        // The kebab-case URI maps back to the camelCase {chartOfAccount}
        // parameter so the controller keeps a conventional variable name.
        // The chart's spreadsheet round trip. Static segments, registered
        // before the resource so they win against `chart-of-accounts/{id}`.
        // There is no index route: the preview renders in a dialog on the
        // chart itself, so `preview` redirects back there.
        Route::get('chart-of-accounts/export', [ChartOfAccountImportController::class, 'export'])
            ->name('chart-of-accounts.export');
        Route::get('chart-of-accounts/import/template', [ChartOfAccountImportController::class, 'template'])
            ->name('chart-of-accounts.import.template');
        Route::post('chart-of-accounts/import/preview', [ChartOfAccountImportController::class, 'preview'])
            ->name('chart-of-accounts.import.preview');
        Route::post('chart-of-accounts/import/confirm/{token}', [ChartOfAccountImportController::class, 'confirm'])
            ->name('chart-of-accounts.import.confirm');
        Route::resource('chart-of-accounts', ChartOfAccountController::class)
            ->parameters(['chart-of-accounts' => 'chartOfAccount'])
            ->except(['show', 'create', 'edit']);

        // Tax rates. Default singular `taxRate` parameter after the
        // kebab-to-camel mapping; no further override needed.
        Route::resource('tax-rates', TaxRateController::class)
            ->parameters(['tax-rates' => 'taxRate'])
            ->except(['show']);

        // Contacts. Create and edit happen in a sheet on the index, so no
        // create/edit pages; `show` is excluded for the same reason as the
        // other accounting resources — the index is the listing surface.
        // Bring the school's parents in as billing contacts. Static segments
        // registered before the resource so they win against
        // `contacts/{contact}`.
        Route::get('contacts/import-guardians', [GuardianImportController::class, 'index'])
            ->name('contacts.import-guardians.index');
        Route::post('contacts/import-guardians/preview', [GuardianImportController::class, 'preview'])
            ->name('contacts.import-guardians.preview');
        Route::post('contacts/import-guardians/confirm/{token}', [GuardianImportController::class, 'confirm'])
            ->name('contacts.import-guardians.confirm');

        // The spreadsheet round trip: take the register out, correct it, put
        // it back. Static segments, registered before the resource for the
        // same reason as the guardian import above.
        Route::get('contacts/export', [ContactImportController::class, 'export'])
            ->name('contacts.export');
        Route::get('contacts/import', [ContactImportController::class, 'index'])
            ->name('contacts.import.index');
        Route::get('contacts/import/template', [ContactImportController::class, 'template'])
            ->name('contacts.import.template');
        Route::post('contacts/import/preview', [ContactImportController::class, 'preview'])
            ->name('contacts.import.preview');
        Route::post('contacts/import/confirm/{token}', [ContactImportController::class, 'confirm'])
            ->name('contacts.import.confirm');
        Route::resource('contacts', ContactController::class)
            ->except(['show', 'create', 'edit']);

        // Journal entries. Unlike the other accounting resources this keeps
        // its create/edit pages: the line grid needs the width (see the
        // controller docblock).
        //
        // post / reverse are registered BEFORE the resource so their static
        // segments win against `journal-entries/{journalEntry}` — the same
        // ordering constraint as the period transitions above.
        Route::post('journal-entries/{journalEntry}/post', [JournalEntryController::class, 'post'])
            ->name('journal-entries.post');
        Route::post('journal-entries/{journalEntry}/reverse', [JournalEntryController::class, 'reverse'])
            ->name('journal-entries.reverse');
        Route::resource('journal-entries', JournalEntryController::class)
            ->parameters(['journal-entries' => 'journalEntry']);

        // Accounting periods. `destroy` is excluded on purpose — the policy
        // refuses deletion outright, because Slice 2 attaches journal entries
        // to periods and removing one would orphan them.
        //
        // The close / reopen transitions are registered BEFORE the resource
        // so their static segments win the match against
        // `accounting-periods/{accountingPeriod}`. Same ordering constraint
        // as `schools/switch` and `contribution-tables/template` above —
        // moving them below the resource would route them into show/update
        // with "close" bound as a literal id.
        Route::post('accounting-periods/{accountingPeriod}/close', [AccountingPeriodController::class, 'close'])
            ->name('accounting-periods.close');
        Route::post('accounting-periods/{accountingPeriod}/reopen', [AccountingPeriodController::class, 'reopen'])
            ->name('accounting-periods.reopen');
        Route::resource('accounting-periods', AccountingPeriodController::class)
            ->parameters(['accounting-periods' => 'accountingPeriod'])
            ->except(['show', 'destroy']);

        // Phase 5 Slice 5 — invoices and bills.
        //
        // The approve / void transitions are registered BEFORE the resource
        // so their static segments win the match against
        // `invoices/{invoice}` — the same ordering constraint as
        // `journal-entries/{journalEntry}/post` above.
        Route::post('invoices/{invoice}/approve', [InvoiceController::class, 'approve'])
            ->name('invoices.approve');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])
            ->name('invoices.void');
        Route::get('invoices/{invoice}/print', [InvoicePrintController::class, 'show'])
            ->name('invoices.print');
        // Mints the customer-facing pay link on first use. Registered before
        // the resource, like the other invoice transitions.
        Route::post('invoices/{invoice}/pay-link', [InvoiceController::class, 'payLink'])
            ->name('invoices.pay-link');
        // Emails the invoice to its payer, at an address the operator can
        // change first. Before the resource, like the other transitions.
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])
            ->name('invoices.send');
        Route::resource('invoices', InvoiceController::class);

        // Recurring schedules. Pause/resume are registered before the resource
        // so their static segments win against `recurring-invoices/{id}`, the
        // same ordering constraint as the invoice transitions above.
        Route::post('recurring-invoices/{recurringInvoice}/pause', [RecurringInvoiceController::class, 'pause'])
            ->name('recurring-invoices.pause');
        Route::post('recurring-invoices/{recurringInvoice}/resume', [RecurringInvoiceController::class, 'resume'])
            ->name('recurring-invoices.resume');
        // No `create` or `store`: a schedule is set up on the invoice form,
        // while the first invoice is being raised. What is left here manages
        // schedules that already exist.
        Route::resource('recurring-invoices', RecurringInvoiceController::class)
            ->parameters(['recurring-invoices' => 'recurringInvoice'])
            ->except(['show', 'create', 'store']);

        // Phase 5 Slice 7 — payments and allocation.
        //
        // The post / void transitions are registered BEFORE the resource so
        // their static segments win the match against `payments/{payment}`,
        // the same ordering constraint as the invoice transitions above.
        Route::post('payments/{payment}/post', [PaymentController::class, 'post'])
            ->name('payments.post');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])
            ->name('payments.void');
        Route::resource('payments', PaymentController::class);

        // The school's own identity — logo, registered name, TIN, address.
        // No school id in the URL: it always edits whichever tenant the
        // request resolved to, so there is nothing to tamper with.
        Route::get('organisation', [OrganisationController::class, 'edit'])
            ->name('organisation.edit');
        Route::patch('organisation', [OrganisationController::class, 'update'])
            ->name('organisation.update');

        // Payment gateway credentials. No `destroy` — a row nobody wants is
        // deactivated, which stops it being used while keeping the record of
        // which gateway a historical payment was taken through.
        Route::get('payment-gateways', [PaymentGatewaySettingController::class, 'index'])
            ->name('payment-gateways.index');
        Route::post('payment-gateways', [PaymentGatewaySettingController::class, 'store'])
            ->name('payment-gateways.store');

        // Phase 5 Slice 9 — the cutover snapshot.
        //
        // Static segments throughout, so no ordering constraint against a
        // wildcard — but `confirm/{token}` carries a session-issued uuid
        // rather than a model, so there is deliberately no route-model
        // binding here to substitute.
        Route::get('opening-balances', [OpeningBalanceController::class, 'index'])
            ->name('opening-balances.index');
        Route::get('opening-balances/template', [OpeningBalanceController::class, 'template'])
            ->name('opening-balances.template');
        Route::post('opening-balances/preview', [OpeningBalanceController::class, 'preview'])
            ->name('opening-balances.preview');
        Route::post('opening-balances/confirm/{token}', [OpeningBalanceController::class, 'confirm'])
            ->name('opening-balances.confirm');

        // Phase 5 Slice 9 — the documents behind that snapshot.
        //
        // Same shape and the same session-token contract; separate because
        // the balances have to exist before the items that explain them.
        Route::get('opening-items', [OpeningItemController::class, 'index'])
            ->name('opening-items.index');
        Route::get('opening-items/template', [OpeningItemController::class, 'template'])
            ->name('opening-items.template');
        Route::post('opening-items/preview', [OpeningItemController::class, 'preview'])
            ->name('opening-items.preview');
        Route::post('opening-items/confirm/{token}', [OpeningItemController::class, 'confirm'])
            ->name('opening-items.confirm');

        // Phase 5 Slice 8a — ledger reports.
        //
        // GET-only and read-only, so no resource controller: each report is a
        // page plus its export sibling. Registered before nothing in
        // particular — `reports/` collides with no wildcard segment — but kept
        // together so the three stay discoverable as a group.
        // The accounting dashboard sits with the ledger reports because it is
        // one: the same posted entries, subtotalled rather than listed, and
        // authorised through the same `viewAny` on JournalEntry.
        Route::get('reports/accounting-dashboard', FinancialDashboardController::class)
            ->name('reports.accounting-dashboard');
        // The invoice dashboard is the operational counterpart, gated on
        // Invoice rather than JournalEntry: chasing payments should not
        // require being handed the school's profit.
        Route::get('reports/invoice-dashboard', ReceivablesDashboardController::class)
            ->name('reports.invoice-dashboard');
        Route::get('reports/trial-balance', [LedgerReportController::class, 'trialBalance'])
            ->name('reports.trial-balance');
        Route::get('reports/trial-balance/export', [LedgerReportController::class, 'trialBalanceExport'])
            ->name('reports.trial-balance.export');
        Route::get('reports/general-ledger', [LedgerReportController::class, 'generalLedger'])
            ->name('reports.general-ledger');
        Route::get('reports/general-ledger/export', [LedgerReportController::class, 'generalLedgerExport'])
            ->name('reports.general-ledger.export');
        Route::get('reports/journal-report', [LedgerReportController::class, 'journal'])
            ->name('reports.journal-report');
        Route::get('reports/journal-report/export', [LedgerReportController::class, 'journalExport'])
            ->name('reports.journal-report.export');

        // Phase 3 W9 — dev/demo affordances. Class-level Gate enforces
        // super-admin + non-production; the controller carries a defense-
        // in-depth abort for the production case.
        Route::post('dev/seed-demo-salaries', [DevSeedController::class, 'seedDemoSalaries'])
            ->name('dev.seed-demo-salaries');
    });
