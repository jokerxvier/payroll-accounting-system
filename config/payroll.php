<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Employee role allowlist
    |--------------------------------------------------------------------------
    |
    | LMS `role_id` values (from the read-only `roles` table) that count as
    | employees of the company for payroll purposes. Anything outside this list
    | is filtered out of the payroll directory and never receives a payslip.
    |
    | LMS roles observed at project start (id => name):
    |   1 => Super admin              4 => Teacher
    |   5 => Admin                    6 => Accountant
    |   7 => Receptionist             8 => Librarian
    |   9 => Driver                  10 => Operations Manager
    |  11 => Branch Manager          12 => Supervisor
    |  13 => Accounts/Finance Officer 14 => Admin Officer
    |  15 => Human Resources Officer 16 => Marketing
    |  17 => Sales                   18 => QMS Coordinator
    |  19 => Registrar               20 => Cashier
    |  21 => Maintenance
    |
    | Roles 2 (Student) and 3 (Parents) are intentionally excluded — they are
    | not company employees.
    |
    | The exact final list MUST be confirmed against the live LMS roles table
    | with the client. PLAN.md §8 lists this as a Week-2 client dependency.
    |
    | Override via env: PAYROLL_EMPLOYEE_ROLES="1,4,5,6,7,8,9,..."
    |
    | TODO: confirm with client (PLAN.md §8 dependency).
    */

    'employee_role_allowlist' => array_values(array_filter(array_map(
        'intval',
        explode(',', (string) env('PAYROLL_EMPLOYEE_ROLES', '1,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21')),
    ))),

    /*
    |--------------------------------------------------------------------------
    | LMS role -> Payroll role mapping
    |--------------------------------------------------------------------------
    |
    | Maps an LMS `role_id` to one of the five payroll role slugs from PLAN.md:
    | super-admin, payroll-officer, hr, auditor, employee.
    |
    | Applied on first login (Slice C). Any LMS role not present in this map
    | falls back to the `employee` role.
    |
    | TODO: confirm with client (PLAN.md §8 dependency).
    */

    'lms_role_to_payroll_role' => [
        1 => 'super-admin',          // Super admin
        6 => 'payroll-officer',      // Accountant
        13 => 'payroll-officer',     // Accounts / Finance Officer
        15 => 'hr',                  // Human Resources Officer
        // All other allowlisted roles default to 'employee' at lookup time.
    ],

    /*
    |--------------------------------------------------------------------------
    | Sidebar hidden sections
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of things to hide from the nav. Presentational only
    | — authorisation is unchanged; direct URLs still resolve for authorised
    | users, and nothing here removes a feature.
    |
    | Two granularities:
    |
    |   - a whole GROUP, by its lowercased label:
    |       directory, payroll, catalog, accounting, audit, tenants
    |
    |   - a single ITEM, by its `hideKey` (see `NavItem` in
    |     resources/js/types/navigation.ts):
    |       accounting.chart-of-accounts, accounting.journal,
    |       accounting.invoices, accounting.bills, accounting.payments,
    |       accounting.contacts, accounting.tax-rates, accounting.periods,
    |       accounting.trial-balance, accounting.general-ledger,
    |       accounting.journal-report, accounting.opening-balances,
    |       accounting.payment-gateways
    |
    | A group whose every item is hidden disappears along with its label.
    |
    | THE DEFAULT BELOW IS THIS SPRINT'S SCOPE, not a permanent decision.
    |
    | Presenting: Chart of Accounts, Backlog Recording, and Invoicing — plus
    | everything payroll and employees, which stays. Contacts and Periods are
    | deliberately NOT hidden even though they are not on the list: an invoice
    | cannot be raised without a customer, nor approved without an open
    | period, so hiding them would dead-end the very flow being demonstrated.
    | The three ledger reports stay for the same reason — "adjust reports
    | accordingly" needs something to point at.
    |
    | To re-enable a feature: delete its entry here, or override the whole
    | list from .env and `php artisan config:clear`.
    |
    | Override via env: PAYROLL_SIDEBAR_HIDDEN_SECTIONS="payroll,audit"
    */

    'sidebar_hidden_sections' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PAYROLL_SIDEBAR_HIDDEN_SECTIONS', implode(',', [
            // Not this sprint. Each is a working feature, only hidden.
            'audit',
            'tenants',
            'accounting.payment-gateways',
            'accounting.journal',
            'accounting.bills',
            'accounting.payments',
            'accounting.tax-rates',
        ]))),
    ))),

];
