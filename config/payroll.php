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

];
