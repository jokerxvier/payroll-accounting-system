<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\Accounting\AccountingPeriodController;
use App\Http\Controllers\Admin\Accounting\ChartOfAccountController;
use App\Http\Controllers\Admin\Accounting\JournalEntryController;
use App\Http\Controllers\Admin\Accounting\TaxRateController;
use App\Http\Controllers\Admin\AllowanceController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DeductionTypeController;
use App\Http\Controllers\Admin\DevSeedController;
use App\Http\Controllers\Admin\EmployeeBulkImportController;
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
        Route::resource('chart-of-accounts', ChartOfAccountController::class)
            ->parameters(['chart-of-accounts' => 'chartOfAccount'])
            ->except(['show', 'create', 'edit']);

        // Tax rates. Default singular `taxRate` parameter after the
        // kebab-to-camel mapping; no further override needed.
        Route::resource('tax-rates', TaxRateController::class)
            ->parameters(['tax-rates' => 'taxRate'])
            ->except(['show']);

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

        // Phase 3 W9 — dev/demo affordances. Class-level Gate enforces
        // super-admin + non-production; the controller carries a defense-
        // in-depth abort for the production case.
        Route::post('dev/seed-demo-salaries', [DevSeedController::class, 'seedDemoSalaries'])
            ->name('dev.seed-demo-salaries');
    });
