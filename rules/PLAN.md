# PLAN.md — Payroll & Accounting System

Phased delivery plan for the standalone Payroll & Accounting system attached to the existing LMS database.

**Timeline:** 4 months (16 weeks).
**Stack:** Laravel 13 · Inertia v3 · React 19 · TypeScript · MySQL 8 · Redis · shadcn/ui.
**Companion docs:** `THEME.md`, `RULES.md`, `CODING_STANDARDS_LARAVEL.md`, `CODING_STANDARDS_REACT.md`.

---

## 1. Context

The client operates an existing Learning Management System (LMS) with employee records (faculty, admin staff) already maintained in its database. They have requested a **standalone Payroll & Accounting application** (single project, both modules ship from this codebase) that:

- Reads employee identity from the LMS database (read-only)
- Owns its own `pas_*`-prefixed tables for payroll, deductions, contributions, audit, and (in a later phase) accounting
- Computes Philippine statutory contributions (BIR, SSS, PhilHealth, Pag-IBIG) for employee and employer shares
- Generates payslips, batch payroll runs, and exportable reports
- Maintains a complete audit trail for every financial mutation
- Never reads, writes, or migrates any LMS table outside of the read-only surface below. The LMS's legacy payroll and accounting tables (`sm_hr_*`, `sm_chart_of_accounts`, `sm_add_incomes`, `sm_add_expenses`, `sm_fees_*`, etc.) are explicitly ignored — the new system replaces them with its own implementation.

  **The permitted read surface** is `users`, `sm_staffs`, `sm_designations`, `sm_human_departments`, `roles` — and, since 2026-08-30, **`sm_students` and `sm_parents`**.

  *Amended 2026-08-30, answering Open Question 2.* A school invoices families, and a family is a parent paying for one or more students. Keeping student and parent records out of reach meant the contact register had to be typed in by hand and kept in step by hand — which is how one family becomes two contacts with half a receivable each. Reading is strictly one-way through `App\Models\Lms\{Student,Guardian}` on `ReadOnlyModel`, guarded by `tests/Feature/LmsReadOnlyTest.php`, and the amendment is deliberately narrow: **`sm_fees_*` stays out**, because this system owns the fee schedule and reading theirs would contradict the sentence above.

The system is logically decoupled from the LMS (separate `lms` connection, read-only models, prefixed tables) so payroll can evolve, be audited, and be reasoned about independently — even while sharing the same physical MySQL database during v1.

---

## 2. Goals & Non-Goals

### Goals (v1)

1. Replace any spreadsheet-based payroll workflow with a system that computes correctly, consistently, and traceably.
2. Support semi-monthly and monthly payroll cycles with locked periods.
3. Generate compliant payslips (PDF) and bulk-export them.
4. Provide bulk import/export via Excel for employee data.
5. Maintain an immutable audit trail for every payroll-affecting change.
6. Deliver a UI that matches `THEME.md` and follows `RULES.md` for both desktop and print.

### Non-Goals (v1)

These are deliberately deferred — they are not failures of v1, they are scope decisions:

- General Ledger posting and journal entries (the "Accounting" side) — see Section 11.
- Government e-filing integrations (BIR EFPS, SSS R3 file generation, PhilHealth EPRS upload).
- Year-end tax annualization and BIR Form 2316 generation.
- Loan amortization scheduling (custom deductions exist; full loan ledger does not).
- Time-and-attendance integration with biometric devices.
- Multi-company / multi-branch payroll (one company, one currency).
- Mobile native app (the responsive web UI works on phones).

---

## 3. Architecture Summary

Detailed conventions live in `CODING_STANDARDS_LARAVEL.md` (backend) and `CODING_STANDARDS_REACT.md` (frontend). Key shape:

- **Layered backend:** Controllers → Services / Actions → Repositories → Models. Strict separation enforced.
- **Money as integer centavos** with a `Money` value object. No floats anywhere in financial paths.
- **Shared physical DB, logical split.** The LMS lives in MySQL database `payroll_db`. The app uses two named connections in `config/database.php`: a read/write default `mysql` connection for app-owned tables, and a separate `lms` connection (same DB, read-only at the app layer via `App\Models\Lms\ReadOnlyModel`). A future physical split is a connection-string change with zero app code change.
- **App-owned tables carry the `pas_` prefix** (Payroll & Accounting System). Examples: `pas_employee_profiles`, `pas_pay_periods`, `pas_payroll_runs`, `pas_payslips`, `pas_chart_of_accounts`, `pas_journal_entries`, `pas_audit_logs`. Framework tables that must remain in DB are also prefixed: `pas_migrations`, `pas_jobs`, `pas_failed_jobs`, `pas_notifications`, `pas_password_resets`. Migrations target only `pas_*` tables; CI enforces this with a positive allowlist test.
- **Cache, queue, and sessions run on Redis** (already available via Herd) to avoid colliding with the LMS's framework tables.
- **Authentication:** Fortify authenticates against the existing LMS `users` table (bcrypt passwords already set). No new auth table.
- **Identity is read-only.** The app reads `users`, `sm_staffs`, `sm_designations`, `sm_human_departments`, and `roles` from the LMS connection. Edits to LMS-owned identity fields (name, email, role, department) happen in the LMS itself; the payroll app edits only payroll-owned fields (salary, statutory IDs, bank, deductions, etc.).
- **Frontend:** Inertia pages + composed shadcn primitives. `useForm` for CRUD; Zustand for client UI state only.
- **Audit trail:** Single morphable `pas_audit_logs` table written via a global event listener.
- **Period locks:** Closed accounting periods are immutable. Corrections require reversing entries in an open period.

### Naming conventions

- App-owned table prefix: **`pas_`**
- App-owned model namespace: **`App\Models\Pas\…`**
- LMS read-model namespace: **`App\Models\Lms\…`** (every model in this namespace extends `ReadOnlyModel`)
- Migrations only create / alter `pas_*` tables — `MigrationSafetyTest` fails if any migration references a non-`pas_` table

---

## 4. Team & Working Assumptions

| Assumption | Notes |
|---|---|
| Developer headcount | 1 full-time developer (Jason), with optional design / QA support |
| Working hours | ~30 productive hours per week per resource |
| Client review cadence | Bi-weekly demo + written sign-off at each phase gate |
| Environment | Dev (local Laravel Herd), Staging (AWS Forge-managed), Production (AWS Forge-managed) |
| LMS DB access | Already provisioned — `.env` points at `payroll_db`. A separate read-only `lms` connection is added in `config/database.php` in Week 1. |
| Government contribution rates | Client provides the latest official tables before week 5 |
| Sample employee data | Sanitized export of 20+ employees from the LMS by end of week 1 |
| Approval roles | Defined by client by end of week 2: who can draft, approve, post, void |

If any assumption breaks, the timeline shifts. Track in the risk register (Section 9).

---

## 5. Phase Breakdown

### Phase 1 — Foundation & Employee Management (Weeks 1–4)

**Goal:** A deployable shell with authenticated users, the LMS bridge proven, and the Employee Management module working end to end.

#### Week 1 — Project setup
- [x] Repo bootstrap from Laravel starter kit (Inertia + React variant).
- [x] `THEME.md` tokens applied to `app.css`; `lucide-react` and shadcn CLI configured.
- [x] CI pipeline: Pint, PHPStan (level 8), Prettier, ESLint, `tsc --noEmit`, Pest, Vitest.
- [x] Local Herd environment + sample `.env.example`.
- [ ] Forge environments provisioned (staging + production).
- [x] **Configure Redis** (Herd-bundled) for cache, queue, and sessions: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`, `SESSION_DRIVER=redis`.
- [x] **Rename Laravel's migrations table to `pas_migrations`** via `config/database.php` (`migrations.table = 'pas_migrations'`).
- [x] Add `MigrationSafetyTest` (positive allowlist: every migration must touch only `pas_*` tables).
- [x] `CLAUDE.md` and rule docs committed at root.
- [x] Delete starter-kit migrations that would damage LMS data (`create_users_table`, `create_cache_table`, `create_jobs_table`, `add_two_factor_columns_to_users_table`).
- [x] Disable Fortify two-factor authentication in v1 (LMS `users` table is read-only; revisit with custom provider in a later phase).

#### Week 2 — Database & LMS bridge
- [x] App-owned migrations (all `pas_` prefix): `pas_audit_logs`, `pas_accounting_periods`, `pas_jobs`, `pas_failed_jobs`, `pas_notifications`, `pas_password_resets`.
- [x] Add **`lms` read-only DB connection** to `config/database.php` (initially mirroring `DB_*` env vars; ready for a future physical split).
- [x] Create `App\Models\Lms\ReadOnlyModel` base class that throws on any write operation.
- [x] LMS read-models extending `ReadOnlyModel`: `Lms\User` (`users`), `Lms\Staff` (`sm_staffs`), `Lms\Designation` (`sm_designations`), `Lms\Department` (`sm_human_departments`), `Lms\Role` (`roles`).
- [x] `LmsReadOnlyTest` and `App\Exceptions\LmsWriteException` ensuring writes are blocked at runtime (defense in depth: Eloquent events + method overrides).
- [x] `Employee` repository bridging LMS staff records to app data.
- [x] **`pas_employee_profiles` table** for payroll-specific fields not in LMS (TIN, SSS, PhilHealth, Pag-IBIG numbers, bank details, salary configuration in integer centavos, employment classification).
- [x] **`config/payroll.php`** new file: `employee_role_allowlist` (which LMS `role_id` values count as employees), `lms_role_to_payroll_role` map (LMS's 21 roles → app's 5-role matrix).
- [x] Spatie permission seeded on app-owned tables with role matrix: `super-admin`, `payroll-officer`, `hr`, `auditor`, `employee`. Mapping from LMS roles is applied on first login.
- [x] Fortify configured to authenticate against the LMS `users` table (read-write only for `password` reset; all other fields untouched).
- [x] Auth flows from starter kit verified in dev, staging, production. (dev verified; staging/production deferred — Forge envs unprovisioned)

#### Week 3 — Employee directory
- [x] Paginated `Index` with filters: status, department, employment type, search.
- [x] URL-synced filters via `useTableFilters` hook.
- [x] Eager-loading and N+1 prevention verified.
- [x] `<PageHeader>`, `<StatCard>`, `<Money>` components shipped to project.
- [x] Empty state, loading skeleton, error state on the index.

#### Week 4 — Employee profile + inline editing
- [x] `Show` page: identity (read from LMS), employment, salary configuration, deduction subscriptions placeholder.
- [x] `Edit` sheet for payroll-owned fields (does not write to LMS).
- [x] Inline edit of salary fields with optimistic UI + Inertia roll-back on error.
- [x] Salary configuration: basic salary, pay frequency (monthly / semi-monthly), tax status, government IDs.
- [x] Deduction subscriptions UI (placeholder card on Show page; actual table + form land in Phase 2 / Week 7).
- [ ] Phase 1 demo + sign-off.

Bonus shipped beyond the original Week 4 scope:
- Quick-edit dropdown per row → expanded into an inline editable row with section tabs (Salary, Status, Government IDs, Bank).
- Reusable `<DatePicker>` (shadcn Popover + Calendar); `THEME.md` §5.8 added; native `<Input type="date">` retired across the app.
- Inline salary editor with explicit ✓ / ✕ confirmation buttons (Enter / Esc keyboard parity).
- JSON profile fragment endpoint (`employees.profile.json`) for lazy-fetch from the directory.

**Phase 1 acceptance criteria**

- [x] Auth + RBAC working in production, with at least the five roles seeded (dev only; production cutover pending)
- [x] LMS read-only connection proven; writes to LMS tables raise an exception
- [ ] Employee directory paginates, filters, and searches at least 20 records under 200ms (perf measurement still owed)
- [x] Employee profile saves payroll-only fields without affecting LMS data (verified by `EmployeeProfileUpdateTest::it does not mutate any LMS-owned table`)
- [x] Audit log captures every employee-profile mutation with actor, before, after (live DB at 2026-05-02 shows 30 rows: created/updated, actor_id + ip + user_agent populated, updated rows carry only changed columns)
- [ ] CI green on `main`; staging deployed automatically on push (Forge envs not yet provisioned — client infra dependency)

---

### Phase 2 — Payroll Computation Engine (Weeks 5–8)

**Goal:** Compute a single employee's payroll for a single period, end to end, including all statutory contributions, deductions, and adjustments. UI shows real-time gross-to-net.

This phase is the highest-risk in the project. The computation engine must be correct, well-tested, and configurable.

#### Week 5 — Government contribution tables
- [x] Migrations + models (all `pas_` prefix). Shipped as a **single unified `pas_statutory_contributions` table** with an `algorithm` discriminator + JSON `rules` payload (instead of 4 PH-specific tables). Future-friendly for additional jurisdictions / contribution types.
- [x] Effective-dating: each row has `effective_from` and `effective_to`. `scopeForDate()` is the single date-filtering primitive (effective_to exclusive on the day-of-supersession boundary).
- [x] Admin UI at `/admin/contribution-tables` for the `super-admin` role: list grouped by code, "Add new version" form with algorithm-aware subforms (BIR brackets, SSS bands, PhilHealth percent-with-cap, Pag-IBIG tiered). New version supersedes the prior row's `effective_to` in one transaction. Audit log fires on both rows.
- [x] Seed v1 with rates current as of the project start. `StatutoryContributionSeeder` covers TRAIN-law BIR brackets (2023+), SSS January 2025 (61 bands), PhilHealth 2024 (5%, ₱10k floor / ₱100k ceiling), Pag-IBIG (HDMF Circular 460, Feb 2024). Sources cited in PHPDoc.
- [x] Unit tests covering boundary values, brackets, and effective-date selection. **143 new tests this week**: 49 Money VO + 6 model effective-date + 56 strategy boundary tests + 7 seeder smoke tests + 25 admin controller tests.

Bonus (added beyond original scope):
- **`Money` value object** at `app/ValueObjects/Money.php` — immutable, integer centavos, banker's rounding via `dividedBy`. Foundation for all Phase 2 financial paths.
- **Strategy pattern** for contribution algorithms (`BracketTable`, `SalaryBand`, `PercentageWithCap`, `TieredPercentage`) + `StatutoryContributionResolver`. Week 6's computation engine plugs in here.
- **`auth.user.roles` shared Inertia data** + role-gated sidebar nav (resolves the prior backlog item from Phase 1).

#### Week 6 — Computation engine
- [x] `PayrollComputationService` (`app/Services/Payroll/`) + 5 underlying actions in `app/Actions/Payroll/`:
  - [x] `ComputeBasicPay` — monthly vs semi-monthly, pro-ration for partial periods (mid-period hires/terminations), zero-pay short-circuits
  - [x] `ComputeBirWithholdingTax`
  - [x] `ComputeSssContribution` (employee + employer + EC)
  - [x] `ComputePhilhealthContribution` (employee + employer, 50/50)
  - [x] `ComputePagibigContribution` (employee + employer, tiered + capped)
- [x] All amounts in `Money` value object, integer-centavos arithmetic. **Zero floats** in payroll code paths (audited via `grep -rn '(float)\|floatval\|round('` — empty in `app/Actions/Payroll/` and `app/Services/Payroll/`).
- [x] Banker's rounding via `Money::dividedBy()`; documented per action PHPDoc.
- [x] `PayrollComputationResult` DTO with 9 line-items (`PayrollLineItem` VO) for the Week 11 payslip renderer. Buckets: earning / employee_deduction / employer_contribution.
- [x] **63 Pest tests this week**: 23 PayPeriodInput + 24 action unit tests + 6 service composition + 10 reference cases (140 centavo-exact assertions). Far exceeds the 50+ floor.
- [x] **Reference cases** (`tests/Feature/Services/Payroll/ReferenceCasesTest.php`) cover: minimum wage, mid-bracket, top-bracket, semi-monthly first/second halves, mid-month hire, mid-month termination, inactive profile, PhilHealth floor, Pag-IBIG cap. Marked `REPLACE WITH CLIENT REFERENCE CASES` for client override on receipt.

Bonus shipped beyond original scope:
- **`PayPeriodInput` value object** (`app/ValueObjects/PayPeriodInput.php`) — immutable, factories for `monthly` / `semiMonthlyFirst` / `semiMonthlySecond` / `custom`, validates day-count vs frequency. Engine takes this instead of a `pas_pay_periods` row, so the Week 6 engine doesn't depend on Week 9 work.

#### Week 7 — Deductions, loans, allowances
- [x] `pas_deduction_types` (taxable / non-taxable, percent / fixed, employee / employer source).
- [x] `pas_employee_deductions` (subscriptions with amount, schedule, optional end date).
- [x] `pas_employee_loans` (lightweight: principal, balance, monthly amortization). Full loan ledger is out of scope; this captures enough to deduct correctly.
- [x] `pas_payroll_adjustments` (one-off additions or deductions per payroll run: penalties, bonuses, refunds).
- [x] Allowances framework: taxable vs non-taxable allowances, applied per-period — `pas_allowances` + `pas_employee_allowances`, de-minimis cap column shipped (cap value pending client per Q3).
- [ ] Absence handling: leave types from LMS read in; unpaid-day reduction logic. **Partial — `ApplyUnpaidDays` action is wired into the engine but returns zero pending the LMS leave-bridge contract (no `is_paid` / approval-status signal in `sm_leave_requests`). Engine path is plumbed; flipping the stub to a real implementation requires no engine change. Tracked in the action's PHPDoc TODO and `rules/PLAN.md:393` (Q3) + a new follow-up requesting the unpaid-leave classification rule.**
- [x] Service composes deductions, loans, adjustments, and allowances into the final payslip lines — verified by `tests/Feature/Services/Payroll/Week7CompositionTest.php` (16 audit lines, 34 centavo-exact assertions in one end-to-end fixture).

Bonus shipped beyond original scope:
- **Five new actions in `app/Actions/Payroll/`** (`ApplyAllowances`, `ApplyEmployeeDeductions`, `ApplyEmployeeLoans`, `ApplyPayrollAdjustments`, `ApplyUnpaidDays`) with 5 colocated breakdown VOs in `app/Services/Payroll/Breakdowns/`. Mirrors Week-6's 5-action seam; engine stays thin.
- **Admin UI for `DeductionType` + `Allowance`** under super-admin gate, mirroring Week-5 visuals. Sidebar entries gated on the role.
- **Employee Show-page integration**: 4 live cards (deductions, allowances, loans, adjustments) replacing the placeholder, with matching edit sheets following the `edit-sheet.tsx` template + nested resource controllers.
- **`tests/Architecture/PayrollFloatAuditTest.php`** — Pest arch test that grep-scans `app/Actions/Payroll/` and `app/Services/Payroll/` for `(float)`, `floatval`, bare `round(`, and `: float` / `float $param` declarations. Closes the open Phase 2 acceptance criterion at line 213.
- **`PayrollLineItem` extended** with seven new codes (`CODE_ALLOWANCE_TAXABLE`, `CODE_ALLOWANCE_NON_TAXABLE`, `CODE_LOAN_AMORTIZATION`, `CODE_CUSTOM_DEDUCTION`, `CODE_ADJUSTMENT_ADDITION`, `CODE_ADJUSTMENT_DEDUCTION`, `CODE_UNPAID_DAYS`); `PayrollComputationResult` extended with 12 new fields + `unpaidDaysCount`.
- **`ReferenceCasesTest`** grew from 10 to 15 hand-derived cases (cases 11–15 cover allowances, loans, adjustments, and termination + loan interaction).

#### Week 8 — Real-time gross-to-net UI
- [x] Single-employee "preview" page: select employee + period, see live computation — `resources/js/pages/payroll/preview.tsx` + Stage A backend at `app/Http/Controllers/PayrollPreviewController.php`.
- [x] Each input change re-computes server-side via debounced Inertia request (no client-side payroll math) — `resources/js/hooks/use-payroll-preview.ts` (debounce-collapse + isComputing lifecycle + unmount cleanup + explicit cancel covered by `use-payroll-preview.test.ts`, 4/4).
- [x] Detailed breakdown view: gross, statutory deductions, custom deductions, adjustments, allowances, net — wire-shape pinned by `PayrollPreviewTest::it serializes audit lines with a stable wire shape` (canonical bucket + Money centavos/formatted).
- [x] Side-by-side comparison with prior period — derivation rules pinned by the monthly + semi_monthly_first + semi_monthly_second + 2020-floor cases in `PayrollPreviewTest`.
- Phase 2 demo + sign-off.

**Phase 2 acceptance criteria**

- [x] Single-employee payroll computes correctly against a hand-calculated reference set (10 cases minimum) — `ReferenceCasesTest` ships 10 hand-derived cases against the rates seeded in 5D. Marked `REPLACE WITH CLIENT REFERENCE CASES` for replacement when the client provides theirs.
- [x] All four statutory contributions match official tables to the centavo — verified at the strategy layer (5C, 56 boundary tests) and through the engine (6D, 140 centavo-exact assertions).
- [x] Effective-dated contribution tables: changing a rate mid-test does not affect prior periods — verified in `PayrollComputationServiceTest::it picks the contribution row effective on period.end()`.
- [x] Real-time preview updates within 500ms of input change — pinned by `tests/Feature/PayrollPreviewPerformanceTest.php` against the server-compute budget at `rules/PLAN.md:336` (warm-up + 3 warm samples, each asserted < 500 ms; observed run 2026-05-03: 5.38 / 4.60 / 3.99 ms).
- [x] Computation engine has 80%+ unit test coverage; every edge case in the test plan is covered — 63 new tests this week across all five actions, the service composer, and 10 reference cases.
- [x] Zero floats in any payroll computation code path — automated by `tests/Architecture/PayrollFloatAuditTest.php` (Week 7 quick win)

---

### Phase 3 — Batch Processing & Documents (Weeks 9–12)

**Goal:** Run payroll for the entire company in one operation, approve and lock the period, and produce payslips as PDFs in bulk. Excel import for employee data.

#### Week 9 — Pay periods & payroll runs
- `pas_pay_periods` table (period code, start, end, cutoff, frequency, status).
- `pas_payroll_runs` table (pay period, status: draft → computing → computed → pending_approval → approved → posted → voided, totals).
- `pas_payslips` table (one per employee per run, with denormalized snapshot of computation).
- "Generate payroll" action: computes for all active employees in the period, queued via Laravel jobs for scale.
- Progress UI driven by partial Inertia reloads (no websockets in v1).

#### Week 10 — Approval workflow & period locking
- Approval workflow with explicit transitions and policy checks per transition.
- Period locking: once `approved`, the run is immutable. Editing requires voiding and re-running.
- Voiding creates an audit entry but leaves payslips visible (marked Voided).
- Bulk action toolbar on the run detail page (re-compute one employee, exclude from run, add adjustment).
- Email notification to approver when a run enters `pending_approval` (queued).

#### Week 11 — Payslip generation
- Standard payslip layout per `THEME.md` Section 6.4 (serif masthead, mono reference, money in `tabular-nums`).
- Print stylesheet verified across Chrome and Safari print preview.
- Single-payslip view (HTML).
- Single-payslip download (PDF, via `dompdf` or `barryvdh/laravel-snappy` — final choice in week 11).
- Bulk PDF: zipped per-employee PDFs, generated via queued job, downloadable from the run page when ready.
- Email payslip to employee (queued, configurable per run).

#### Week 12 — Excel integration
- Download templates: employee bulk-edit template, contribution table import template.
- Upload + validation: row-level errors surfaced in the UI before any DB writes.
- Two-step import: validate → preview diff → confirm.
- Use `maatwebsite/excel` for the import/export pipeline.
- Audit log captures the import as a single composite event with the row-level changeset.
- [x] Phase 3 demo + sign-off (acceptance criteria pinned by `tests/Feature/Acceptance/Phase3AcceptanceTest.php`; client demo TBD).

**Phase 3 acceptance criteria**

- [x] A payroll run for 50 employees completes in under 60 seconds (pinned by `tests/Feature/Acceptance/Phase3AcceptanceTest.php`; sync-queue measurement consistently under 5s on dev hardware)
- [x] Approved runs cannot be edited; UI prevents and server enforces (no `update` ability on `PayrollRunPolicy`; `GeneratePayrollRunAction` rejects re-running on a locked period; the four transition Action classes guard their source-status invariant)
- [x] Single payslip renders identically in screen and print preview (Inertia HTML page + dompdf Blade share `PayrollRunController::payslipViewModel()` as single source; `payslips.pdf` Blade mirrors the React layout)
- [x] Bulk PDF for 50 employees generates in under 2 minutes; output is a single downloadable archive (`BuildBulkPayslipsZipAction` via `Bus::batch` of `RenderPayslipPdfJob`; assembled zip persisted at `payroll-runs/{id}/payslips.zip`. Perf test exists but is gated to manual run with a real queue worker)
- [x] Excel import rejects malformed rows with field-level error messages and writes nothing on partial failure (W12 Stage A; per-row preview surfaces field-level errors; only non-error rows enter the apply transaction)
- [x] All payslips include the employee's TIN, SSS, PhilHealth, Pag-IBIG numbers when present (W11 Stage A; `payslipViewModel()` reads from `pas_employee_profiles` encrypted columns; verified by acceptance test)

---

### Phase 4 — Reports, Audit, Polish & Launch (Weeks 13–16)

**Goal:** Reports module, audit log viewer, end-to-end polish, and production cutover.

#### Week 13 — Reports module
- Payroll summary report: per-period totals across gross, statutory contributions (employee + employer), custom deductions, net.
- Employee history report: per-employee timeline of payslips with running totals.
- Year-to-date views per employee (groundwork for future year-end annualization).
- [x] Each report exportable to Excel, CSV, and PDF. (xlsx shipped in W13; csv + pdf added 2026-08-24 — dompdf views under `resources/views/reports/`.)
- Server-side report generation queued; UI shows status and download link when ready.
- Filters: date range, employee, department, employment type, status.

#### Week 14 — Audit log viewer
- Read-only audit log UI with filters (actor, action, date range, target).
- Detail drawer showing before/after diff per entry.
- Export audit log to CSV for external review.
- [x] Verify every payroll-affecting action across phases produces an audit entry — gap analysis. Executable as `tests/Feature/Acceptance/AuditCoverageTest.php`. Two gaps found and fixed: `School` was never audited, and payslips destroyed by the payroll-run delete cascade left no trail. Documented non-audits: the `SchoolObserver` bulk catalog clone (deliberate — it is a system copy, not a user edit) and the login-time `pas_users` upsert (LMS identity sync, not a payroll action).
- Retention policy documented (e.g., audit logs retained indefinitely; payroll runs retained for at least 10 years per Philippine tax record requirements).

#### Week 15 — UAT, performance, and bug fixes
- Client UAT with sample payroll run on real (sanitized) data.
- Performance pass: query profiling on the 5 slowest pages, index audit, N+1 audit.
- Accessibility pass: WCAG AA verification on all critical pages.
- Cross-browser smoke (Chrome, Edge, Safari, Firefox).
- Bug triage and fix; defer non-critical issues to a backlog.

#### Week 16 — Documentation, deployment, handover
- User documentation: HR/payroll officer guide (Markdown + screenshots).
- Admin documentation: how to update contribution tables, manage roles, restore from backup.
- Developer handover: this `PLAN.md` retrospectively annotated with what shipped vs deferred; deployment runbook.
- Production deployment with zero-downtime cutover (or a documented maintenance window).
- 1-week hypercare period scheduled post-launch (Week 17 — outside this plan but noted).
- Final sign-off.

**Phase 4 acceptance criteria**

- [x] Three reports (summary, employee history, audit) export cleanly to all three formats — xlsx / csv / pdf, via a `format` query parameter on each `/export` endpoint
- [x] Every state-changing action across the system has an audit log entry; spot-check passes for each role — pinned by `tests/Feature/Acceptance/AuditCoverageTest.php`, which drives each endpoint, asserts the row and its actor, and structurally guards that every persisted `pas_*` model carries the Auditable trait
- [ ] Production deployment completes without data loss and the smoke-test checklist passes
- [ ] User documentation reviewed and accepted by client HR contact
- [ ] All P0 and P1 bugs from UAT closed; P2/P3 logged in the backlog with owners

---

### Phase 5 — Invoicing & Accounting (post-v1)

**Goal:** the accounting half of "Payroll & Accounting" — chart of accounts, a balanced
double-entry journal, VAT-aware sales invoices and supplier bills with BIR-compliant numbering,
payment allocation, and the financial reports the client listed in
`Financial Reports With Formula.docx`. Full plan:
`~/.claude/plans/i-want-to-implemement-proud-taco.md`. Section 11 below explains why this was
deferred out of v1.

Ordering is load-bearing: the journal must be trustworthy before any document posts to it.

#### Slice 1 — Ledger foundation
- [x] `pas_chart_of_accounts` — per-school, with `normal_balance` and `cash_flow_category` stored
      explicitly so the General Ledger and Cash Flow reports can be correct.
- [x] `pas_tax_rates` — rates in integer basis points (12% = 1200), never floats.
- [x] `pas_accounting_periods` promoted from a dormant Phase-1 table to a real model, admin UI,
      and close / reopen transitions with actor stamps.
- [x] `SchoolObserver` extended to clone both catalogs onto new schools, remapping the intra-set
      foreign keys (`parent_id`, `account_id`) so a tenant never points at another's rows.
- [x] `AccountingCatalogSeeder` — default Philippine private-school chart + the four VAT rates.
- [x] `accountant` role seeded; `AccountingRoles` shares one role list across all three policies.

#### Slice 2 — Journal entries and invariants
- [x] `pas_journal_entries` + `pas_journal_entry_lines`.
- [x] `PostJournalEntry` asserting debits === credits in cents before persisting.
- [x] Posting guard rejecting any entry dated inside a closed period
      (`AccountingPeriodGuard` — the single gate every posting passes through).
- [x] Correction by reversal — a posted entry is never mutated, and BOTH the
      original and its reversal stay posted so they offset. Marking the original
      voided would drop it from `scopePosted()` and understate the account by the
      full amount. Action is `ReverseJournalEntry`; stamps are `reversed_*`.
- [x] Manual journal entry UI using the debit/credit table from `THEME.md` §6.3,
      with a live running balance so an imbalance is visible before submit.

#### Slice 3 — Payroll → GL posting seam
- [x] `config/accounting.php` mapping payroll components to account codes.
- [x] `posting_payload` JSON column on `pas_payroll_runs`, plus `journal_entry_id`
      and `ledger_posted_at`.
- [x] `LedgerPostingService::post(PayrollRun $run)`, wired into `PostPayrollRunAction`.
      Pays off the Section 11 debt — none of this was built in v1.
- [x] The ledger posting deliberately cannot fail the payroll post. A closed
      period or a missing account mapping logs and leaves `journal_entry_id`
      null; the run is retried once the books are ready, and the post is
      idempotent so retrying is safe. Payroll is not blocked on accounting.

#### Slices 4–7 — Documents
- [x] Contacts (customer / supplier, TIN, per-contact AR/AP control accounts).
      One record with two flags, since plenty of counterparties are both. TIN is
      normalised to digits and unique per school when present. Control accounts
      are OVERRIDES — null means the school's AR_CONTROL / AP_CONTROL system
      account, which is how Slice 5 will resolve them. Deliberately NOT cloned
      to new schools: a customer list is business data, not a catalog template.
      `lms_student_id` exists as a nullable pointer but nothing populates it —
      reading LMS student tables is outside §2 (Open Question 2).
- [x] Sales invoices: gapless BIR numbering, VAT-aware lines, PDF.
      **Numbering was removed on 2026-08-30 at the client's instruction.**
      `DocumentNumberAllocator`, `pas_document_number_series` and its model,
      controller, policy, request, factory, page and tests are gone;
      `ApproveInvoice` no longer allocates. An approved invoice is now
      identified by its id and carries no serial. Everything below about
      gaplessness, Authority To Print and serial ranges describes what the
      slice shipped, not what is in the tree — kept because it records the
      reasoning a reinstatement would need.

      Numbers came back the same day in a much smaller form:
      `InvoiceNumberAllocator` derives `INV-{year}-{00001}` /
      `BILL-{year}-{00001}` from the highest already issued for that school,
      type and issue-date year — no series row, no permit, no setup, and
      nothing that can run out. It allocates at DRAFT CREATION rather than at
      approval, since a number that is only an internal reference has nothing
      to protect by being withheld, and a draft people discuss is easier to
      name. Gaps are tolerated, which is exactly the property that made this
      approach unavailable while the numbers were BIR-controlled.

      Two things were deliberately NOT destroyed: `pas_invoices.number`
      (which still holds SI-000001 and SI-000002, issued before the removal)
      and the `pas_document_number_series` table itself. Dropping either
      would destroy issued-document history to save a column nobody reads.
      The table is orphaned, not dropped; removing it is a separate decision.

      Consequences to weigh before this reaches production: a BIR sales
      invoice must legally carry a serial from an authorised range, the
      printed face no longer has a number or an ATP footer, and Slice 8c's
      Invoice Register has nothing to register.
      `DocumentNumberAllocator` refuses to run outside a transaction, so a
      document that fails to save returns its serial instead of burning it —
      a gap in an authorised range is an audit finding, unlike a gap in the
      internal journal sequence. Issuing past `serial_end` is refused
      outright rather than warned about. `/admin/document-series` is where the
      client's Authority To Print details go; a series without them still
      issues numbers and the printed face simply omits the permit footer.
      Totals carry the three BIR sales buckets separately (VATable, exempt,
      zero-rated) because the return reports them separately and merging them
      loses the distinction for good. Rounding is per line, never on the
      total, so the invoice equals the lines a customer can add up.
      Approval numbers, recomputes, and posts inside one transaction —
      unlike payroll, a ledger failure FAILS the approval, because a numbered
      document must never reach a third party while the books reject it.
      `pas_schools` gained nullable `registered_name` / `tin` /
      `business_address`, which a BIR invoice face requires and the table did
      not carry.
- [ ] Credit notes and official receipts. Split out of the line above rather
      than left implied: both are separate BIR document types with their own
      permits and their own series, and which of them a given school may
      legally issue is Open Question 1, not something to infer in code. The
      `DocumentNumberSeries::TYPE_CREDIT_NOTE` and `TYPE_OFFICIAL_RECEIPT`
      constants already exist, so both are purely additive.
- [x] Supplier bills, reusing the invoice engine with the posting direction
      inverted. Delivered by Slice 5 rather than as a slice of its own: the
      single `type`-discriminated `pas_invoices` table meant the AP path —
      inverted posting, its own numbering series, expense accounts, the
      "Purchase Bill" face — came out of the same code, covered by the same
      tests. Slice 7 added the sidebar entry; until then bills existed but
      were reachable only by hand-editing `?type=purchase`, which is not
      reachable.
- [x] Payments and allocation (partial, multi-invoice).
      One `pas_payments` table with a `type` discriminator (receipt |
      disbursement) and `pas_payment_allocations` joining it to documents, so
      one payment settles several and one document is settled over time.
      `InvoiceBalanceService` is the single place a paid amount is derived,
      and it counts allocations from **posted** payments only — which is what
      keeps a keyed-but-uncommitted draft from marking an invoice paid, and
      what lets a void restore every balance without deleting a single
      allocation row. Overpayment credits its own account (`2410 Advances
      from Customers`, `1450 Advances to Suppliers`) rather than driving the
      receivable negative: an advance is a liability owed back in goods, not
      a receivable owed backwards. Both accounts were backfilled onto existing
      charts. Payments carry a free-text reference rather than a serial — a
      payment records money moving, not a document issued, and drawing from
      the official-receipt series would commit to an answer for Open Question
      1 that the client has not given. `ControlAccountResolver` was extracted
      from `InvoicePostingService` so an invoice debiting a receivable and a
      receipt crediting it cannot disagree about which account that is.
      Closes the Slice 5 gap where nothing could write
      `amount_paid_centavos`: `partially_paid` / `paid` are now reachable,
      `scopeOutstanding()` means something, and `VoidInvoice`'s "reverse the
      payment first" guard fires for the first time.

#### Slice 8 — Financial reports
Split into three, because the thirteen reports fall into three groups that share
nothing but a date filter. 8a reads the ledger directly; 8b classifies and
subtotals what 8a returns; 8c reads invoices and payments rather than the ledger.

**8a — Ledger reports**
- [x] Trial Balance, General Ledger, Journal Report. Each with an Inertia page
      and xlsx / csv / pdf exports, all three built on one
      `LedgerReportService`. Shipped 2026-08-25.

      The Trial Balance is the first code in Phase 5 that adds Slices 1–7
      together, so it states its own verdict on the page rather than leaving
      the reader to foot six columns. Against the dev ledger it closes at
      ₱1,163,770.00 on both sides.

      Two decisions the later slices inherit. **Raw and natural signing are
      kept apart**: the Dr/Cr columns use `debits − credits`, which is what
      makes them foot, and the directional figure the statements will consume
      goes through `ChartOfAccount::movementCentavos()`. Signing early is what
      would make a trial balance stop balancing. **Ranges are taken on the
      entry's own date, never `posted_at`**, so a backdated entry lands in the
      period it belongs to and a closed period's figures cannot move.

      Found and fixed on the way: `pas_journal_entries.date` compares as a
      *string* under SQLite, where Eloquent's date cast writes
      `Y-m-d H:i:s` — so `<= '2026-08-31'` silently dropped the last day of
      every range. Boundaries now go through `dayStart()` / `dayEnd()`, which
      is correct on both databases and keeps the index usable.

      Also fixed: `User::factory()->create(['lms_user_id' => null])` does not
      make a platform admin — the factory's own `afterCreating` hook backfills
      the column afterwards. The Slice 5 and Slice 7 platform-admin regression
      tests were passing without ever reaching the `Gate::before` bypass they
      exist to guard. All three now use `withoutLmsMirror()` and still pass.
- [x] `is_cash_equivalent` on `pas_chart_of_accounts` — the schema addition 8b
      needed before any statement could be written. Shipped 2026-08-25.

      A Cash Flow Statement has to know which accounts *are* cash, and nothing
      recorded that: `cash_flow_category` is set on every account including the
      cash ones, because it says which SECTION an account's movements belong
      to, not whether the account is part of the cash balance those sections
      reconcile to. Two different questions, so a second column rather than
      another value in the first.

      It was also a live control gap, not just missing report data.
      `PaymentController::cashAccountOptions()` approximated cash as "any
      active asset with no `system_code`", and `PaymentRequest` only checked
      that the account was an asset — so a receipt could be posted into Prepaid
      Expenses or Property, Plant and Equipment. Both now key off the flag, and
      the form's picker and the validator enforce the same rule, since a
      payload that skipped the form must not get the weaker one.

      Backfill is deliberately narrow: only the two codes the seeder ships as
      cash (`1100`, `1110`), with the type re-checked so a school that reused
      one of those codes is not swept in. Default false is the safe direction —
      an account wrongly left off is visible immediately (missing from the
      picker), while an account wrongly included is the silent bug this closes.
      A school that renumbered its chart ticks its own accounts in the UI.

      `ValidatesCashEquivalentAccounts` holds the assets-only rule for both
      chart-of-accounts requests. A bank overdraft is arguably a cash
      equivalent under PAS 7, but it is a liability account and admitting
      liabilities to serve that one case opens the door to every payable.
- [x] **8b's first consumer shipped ahead of the statements themselves**: the
      accounting dashboard (`/admin/reports/accounting-dashboard`), which is
      the Income Statement's figures without the statement's face. Three new
      pieces, all in the place 8b's own framing put them — classifying and
      subtotalling what 8a returns rather than writing a second set of SQL:

      `AccountingSummaryService` derives all six tiles from ONE
      `trialBalance()` call, so the tiles cannot disagree with the chart under
      them. `LedgerSeriesService` is the only genuinely new aggregate — income
      and expense per month in one grouped query, because twelve
      `trialBalance()` calls would be twelve scans to draw one picture.
      `FiscalYear` reads `pas_accounting_periods.fiscal_year`, which nothing
      had read since the column was added; a school whose year runs July to
      March was the case a calendar default would have got wrong.

      **The distinction the whole thing turns on**: income and expenses are
      PERIOD movements, cash/AR/AP are CLOSING balances. Reading closing for an
      income account under a one-month filter reports every peso the school has
      ever earned and calls it this month's. `TrialBalanceRow` gained
      `periodNaturalCentavos()` for it, and the signing rule it duplicated
      three times is now one private method.

      Receivables sums the AR control account **and** every
      `pas_contacts.receivable_account_id` override — the system code alone
      understates what a school is owed, in the school's favour.

      Verified by reconciliation, not just by unit tests: the dashboard, the
      trial balance and the summed monthly series agree to the centavo on the
      dev ledger (₱406,001.00 income, ₱858,770.00 expenses).
- [ ] Income Statement, Balance Sheet, Cash Flow Statement, Statement of
      Changes in Equity — the faces, exports and PDFs. The figures now exist;
      what is missing is the presentation and the subtotal hierarchy.

      Note for whoever takes this: the Statement of Changes in Equity's
      "Beginning Retained Earnings" now has a real source. Slice 9's cutover
      snapshot is the first thing in the project to post to
      `SYSTEM_RETAINED_EARNINGS`, so a school that opened its books carries an
      opening figure there rather than the zero this line was written against.

**8c — Receivables and document reports**
- [x] **8c's figures shipped ahead of its reports**: the invoice dashboard
      (`/admin/reports/invoice-dashboard`) on a new `ReceivablesService` — the
      ageing buckets, outstanding balances and collections the Aged Receivables
      and Outstanding Invoices reports will consume rather than re-derive.

      Nothing had ever compared `due_date` to today before this; there was no
      overdue concept in the codebase at all. Four decisions worth keeping:
      ageing uses the **remainder, never the original total** (a ₱10,000
      invoice with ₱9,000 paid owes ₱1,000); **a null due date is Current, not
      overdue**, because the field is optional and its absence means no
      deadline was set; collections range on `payment_date` and are **gross**,
      so Collected + Outstanding reconciles to Invoiced; and **overdue is a cut
      across unpaid and part-paid**, reported beside them rather than as a
      fourth slice that would double-count the same peso.

      Aggregates read `pas_invoices.amount_paid_centavos` rather than
      re-summing allocations: `InvoiceBalanceService` is its only writer and
      every mutation goes through it, so the header is guaranteed in step and
      re-deriving would be a correlated subquery per invoice.

      **The two dashboards differ by design, and on the dev data by exactly
      ₱180,000** — Slice 9's cutover snapshot, posted straight to the AR
      control account with no invoice behind it. Accounting Receivables reads
      the ledger; this reads documents an officer can open and chase. Pinned by
      a test so the divergence is not rediscovered as a bug.
- [ ] Aged Receivables (summary + detail), Customer Statement, Invoice Register,
      Outstanding Invoices, Receipts Report, Credit Notes Report.

      Invoice Register needs rethinking after the 2026-08-30 numbering
      removal: a register is a list of issued serials, and there are no
      serials now. It becomes a list of approved documents keyed by id, or it
      waits for numbering to come back.

      Credit Notes Report stays blocked behind Open Question 1, same as the
      credit-note document itself.

#### Slice 9 — Backlog recording (opening balances)
Split from Slice 8 rather than folded into it: the statements read the ledger,
and this is the first slice that changes what the ledger *starts* with. Scope is
the cutover snapshot only — historical invoices, payments and open items are a
later slice, and the decision they turn on is recorded below.

- [x] `pas_schools.books_opened_on` — nullable, the date a school's books were
      opened here. Stamped by `PostOpeningBalances` inside the posting
      transaction and cleared by `ReverseJournalEntry`, never set by hand: the
      date belongs to the entry that justifies it, and a stamp that outlived
      its snapshot would keep every report captioned for figures that no longer
      contribute a centavo. Null — a school that genuinely started from zero —
      is the honest default and the state every existing row is in.
- [x] `JournalEntry::SOURCE_OPENING_BALANCE`, a `source_type` sentinel with a
      null `source_id`. Every other value in that column is a model FQCN with a
      row behind it; an opening balance describes what the books already said
      before this system existed, so there is nothing to point at. A sentinel
      rather than a new boolean column because "where did this entry come
      from?" is the question `source_type` already exists to answer, and
      nothing morphs the column into a class.
- [x] `PostOpeningBalances` — one entry, built as a draft and handed to
      `PostJournalEntry` like everything else, which is what earns it the
      balance assertion, the period lock and the number without restating any
      of them.

      **Balance-sheet accounts only.** Assets, liabilities and equity carry
      across a year boundary; income and expenses close out to retained
      earnings. Admitting an opening balance on an income account would report
      prior-year trading as current-period earnings and overstate every Income
      Statement from then on — so prior trading compresses into `3200 Retained
      Earnings` as one figure, which is also what makes the snapshot balance.
      This slice is the first code that ever posts to `SYSTEM_RETAINED_EARNINGS`,
      seeded since Slice 1 and until now untouched.

      **One standing snapshot per school.** A second would double every balance
      it touched. The guard discounts both a reversed original and the reversal
      itself, because `ReverseJournalEntry` copies `source_type` onto the
      reversal — so the sentinel alone matches three rows for what is one
      unwound snapshot.

      **The difference is never plugged silently.** An unbalanced sheet means
      the source figures are wrong, and routing the gap to Retained Earnings
      unasked would hide that. The preview states the difference and the user
      ticks the plug, having seen the number.
- [x] Bulk import at `/admin/opening-balances`, reusing
      `EmployeeBulkEditImport`'s four-step shape: template → upload → validated
      preview → confirm. It copies that flow and NOT its authorization —
      employee import gates on inline `hasAnyRole`, which bypasses the
      `Gate::before` platform-admin short-circuit; this goes through
      `JournalEntryPolicy::postOpeningBalance`, which is `POST_LEDGER` and so
      narrower than the rest of the accounting sidebar. The nav item is gated
      separately for that reason, since showing it to the wider group would be
      a dead link for `payroll-officer` and `auditor`.

      Amounts in the worksheet are decimal pesos, not centavos — unlike the
      employee template, which round-trips its own stored integers. Someone
      reading a trial balance off their old system types 1,234.56, and asking
      them to convert invites the error the import exists to prevent. The
      template also omits income, expense and inactive accounts outright: a
      sheet that never offers the wrong row cannot collect the wrong figure.

      A file with any bad row is refused whole rather than part-applied,
      because a partial opening balance is an unbalanced one and the plug would
      then silently cover rows the user believed were included.
- [x] `AccountingPeriodGuard::isOpenOn()` gets its first consumer. It has
      existed since Slice 2 and was called from nowhere: every write path uses
      `resolveOpenPeriodFor()` and finds out on submit. The preview asks ahead
      and blocks confirm with a link to create the covering period, because a
      new school has **no** periods at all — `SchoolObserver` clones catalogs
      but not periods — so "nothing can post yet" is the normal state on day
      one, not an error worth a stack trace.
- [x] Reports adjusted. Less than the phrasing implies, and that is the
      finding: `LedgerReportService` needed **no arithmetic change at all**.
      Because ranges filter the entry's own `date` and `rawBalanceBefore()` /
      `accountSums()` already sweep in everything posted before a range, a
      snapshot dated at cutover lands in the Trial Balance's opening columns
      and the General Ledger's brought-forward row the day it exists. Pinned by
      a test asserting exactly that, rather than built.

      What the reports could not do on their own is say *where* an opening
      figure came from. "Opened at ₱700,000 because of everything posted
      before the range" and "…because ₱700,000 was carried in from the client's
      previous books" read identically in a column of figures and mean
      different things to anyone reconciling them — so `CutoverNote` states it
      on both balance-bearing reports, and the Journal Report badges the
      snapshot so a thirty-line entry is not an unexplained bulk posting.
- [ ] Open items — the unpaid invoices and bills behind the AR/AP control
      balances. Deferred with Slice 8c's ageing reports, which are what would
      consume them: until then the control account is right in total and has no
      sub-ledger to reconcile against. The numbering decision is already taken
      and is the reason this is a slice of its own rather than an extension of
      the one above: a historical document carries **the number it was actually
      issued under**, recorded as given, with `DocumentNumberAllocator` never
      called. Drawing from the live counter would hand a historical document a
      serial higher than invoices with later issue dates already issued from
      that BIR-authorised range, which is an audit finding — and the allocator
      cannot help, because it takes only a document type and has never seen a
      date.

#### Slice 10 — Online payments (PayMongo and Stripe)
The app's first integration of any kind. Before this there were zero outbound
HTTP calls, no `routes/api.php`, no CSRF exclusion, no mail, no signed URLs and
no URL an outside party could reach — so every convention here is established
rather than followed.

- [x] `app/Services/Payments/` — a `PaymentGateway` contract with PayMongo and
      Stripe drivers behind it, and a `GatewayEvent` DTO both normalise to.
      Two gateways, one posting path: nothing downstream can tell which paid.
      Laravel's `Http` client rather than two SDKs, because the surface used is
      a handful of endpoints and that would have been the largest dependency
      addition in the project. Credentials are scrubbed from error messages,
      following `SchoolController::testConnection()` — the only
      integration-hygiene precedent that existed.
- [x] `pas_payment_gateway_settings` — per-school credentials, because each
      school is its own registered entity with its own merchant account and the
      money settles into its own bank. `encrypted` cast plus `auditExclude()`,
      lifted wholesale from `School::lms_db_password`; the reasoning is sharper
      here, since a leaked secret key moves money. Test and live are **separate
      rows**, not one row with a toggle — a mode switch beside a single
      credential field is how a real card gets charged from a sandbox.

      The secret never reaches the browser. Inertia props are serialised into
      the page, so the screen gets `••••4242` and a boolean; the field is
      write-only and a blank submission keeps what is stored. Gated by
      `AccountingRoles::PAYMENT_GATEWAY` — `super-admin` only, the narrowest
      list in the module.
- [x] Public pay page at `/schools/{slug}/pay/{token}` — the first guest-
      reachable route. `pas_invoices.pay_token` is minted **on demand**, so the
      number of live public URLs equals the number someone deliberately asked
      for, and is stable once minted because re-issuing would break a link
      already in a parent's hands.

      **The token is the credential, not the slug.** A guest bypasses
      `ApplyTenantOverride`'s LMS-pinning, so a crafted slug resolves whatever
      it names; and `BelongsToTenant` **fails open** when no tenant is current,
      returning every school's rows. Every query names `school_id` explicitly
      and matches the token alongside it. Every refusal is the same 404 —
      distinguishing "no such token" from "that one is voided" confirms a guess.
- [x] Webhook at `/schools/{slug}/webhooks/{provider}` — first unauthenticated
      POST, first CSRF carve-out in `bootstrap/app.php`. The slug is in the URL
      because `SchoolTenantFinder` resolves only by host, path prefix or
      header, and a gateway sends none of them: a fixed URL would 404 at
      `NeedsTenant` before any controller ran.

      The signature is the only authentication, checked before the payload is
      read. Idempotency is the unique on `pas_gateway_events
      (provider, external_event_id)` — both gateways retry until they get a
      2xx and can redeliver after one, so without it a retry books a second
      payment. A failure *after* acceptance still returns 200: the row exists,
      so retrying changes nothing, and the error is stored for a human.
- [x] `RecordGatewayPayment` **composes** `ApplyPaymentAllocations` and
      `PostPayment` rather than writing `pas_payments` itself. There is one way
      to settle an invoice; this is a different way of starting it, not a
      different way of doing it. It allocates what is still outstanding rather
      than what it was handed, so paying a link twice becomes an advance rather
      than a negative receivable — and posts with a **null actor**, which is
      why `PostJournalEntry`/`PostPayment` now take `?int $actorUserId`.
- [x] The gateway's two account questions removed from the setup screen.
      `5250 Bank and Merchant Fees` added to the catalog carrying
      `SYSTEM_MERCHANT_FEES`, seeded and backfilled onto existing charts the
      same way the advances accounts were. Nothing in the 5xxx range fitted:
      `5900 Miscellaneous Expense` is a dumping ground, `5240 Professional
      Fees` is not what a gateway cut is, and `5400 Interest Expense` is
      categorised `financing` when a merchant fee is `operating`.

      **The two sides resolve by different mechanisms, and the asymmetry is
      load-bearing.** The fee comes from the system code; the cash comes from a
      chart CODE in config, because giving `1110 Cash in Bank` a `system_code`
      would silently drop it from the manual payment picker —
      `PaymentController::cashAccountOptions()` excludes system accounts on
      purpose, and its comment predicted exactly this change. Breaking
      hand-keyed receipts to tidy a gateway form would have been a bad trade.

      Both fields moved into a collapsed **Advanced** section (the first
      disclosure in the admin UI) with a "Use the school default — 1110 · Cash
      in Bank" sentinel, matching the override shape
      `contact-edit-sheet.tsx` already uses. `isUsable()` now asks whether the
      accounts *resolve* rather than whether the columns are set, which is what
      keeps a broken chart failing in front of an operator instead of in front
      of a customer who has already paid.
- [x] Gateway fees split gross from net. `pas_payments.fee_centavos` and
      `fee_account_id` (pinned on the payment, not read back from settings,
      so a posted entry stays reproducible after the settings change).
      `PaymentPostingService` debits cash `amount − fee` and the fee to its own
      expense account: ₱1,120 invoice, ₱28 fee → `Dr Cash 1,092 · Dr Fees 28 ·
      Cr AR 1,120`. Recording the net instead would strand every online invoice
      at `partially_paid` and fill Aged Receivables with residue nobody can
      collect. Manual payments keep `fee = 0` and post exactly as before.
- [x] Emailing the link. Slice 13 built `SendInvoiceEmail` + `InvoiceIssuedMail`
      but wired them to one caller only — `approve()`, and only when
      `recurring_invoice_id !== null` — so a hand-typed invoice still never
      left the building. `POST invoices/{invoice}/send` closes that: a **Send
      by email** button on the invoice, showing the recipient before it goes,
      pre-filled from the payer's address and editable, because the address is
      the thing most likely to be wrong and a school often wants this term's
      bill at a different one. A typed address is used for that send only and
      is deliberately **not** written back to the contact.

      Three things this forced into the open. `SendInvoiceEmail::execute()`
      now takes an optional recipient, and an explicit one **also overrides
      the already-sent guard** — that guard exists so a retried approval
      cannot mail a family twice, not to answer "they never got it" with a
      shrug. The status write had to be conditioned on `approved`: re-sending
      a `partially_paid` invoice would otherwise walk its status back to
      `sent`, and every receivable report follows the status rather than the
      allocations. And `sent_to` (new column) records **where** it went —
      `sent_at` alone answers the wrong half of a delivery dispute.

      `InvoiceStatusBadge` gained its missing `sent` branch at the same time;
      the status was reserved in Slice 5 and, until invoices could be sent by
      hand, was rare enough that a sent invoice reading as merely "Approved"
      had gone unnoticed.
- [ ] A second Horizon queue. There is one supervisor on `default`
      (`config/horizon.php:212-230`), and the webhook currently records
      synchronously rather than queueing behind a payroll batch. Worth
      revisiting if deliveries start timing out.

#### Slice 11 — Parents as billing contacts, linked to their students
- [x] `App\Models\Lms\{Student,Guardian}` on `ReadOnlyModel`. Named `Guardian`
      rather than `Parent` because `parent` is a PHP reserved word and the
      class would not compile. `LmsReadOnlyTest` was extended to prove the
      write-refusal is inherited rather than special-cased — these are the
      tables a bug would corrupt a school's enrolment through.
- [x] `pas_contact_students` — the link, and the reason this is a table rather
      than a column. **One payer, one contact, however many children.** A
      column on `pas_contacts` holds one student, so a parent with two would
      need two contact rows, scattering one family's receivable across two
      counterparties, breaking their statement and counting them twice in
      Aged Receivables.

      Many-to-many in both directions on purpose: a contact has several
      children, and a student has a primary payer who may be joined by a
      sponsor or a second guardian. **The LMS models none of that** — one
      `sm_parents` row holds father, mother and guardian as *text columns*,
      with no payer designation and no second slot — so the whole billing
      relationship lives here. `is_primary_payer` is enforced in the action,
      not the schema: "exactly one of a filtered set" is not something a
      unique index can express, and a partial index does not port to sqlite.

      `student_name` is a snapshot, because the LMS is another connection and
      listing a contact's children should not need a cross-database join.
- [x] `pas_contacts.lms_parent_id`, unique per school — the import's only
      certain de-duplication key. **Never globally unique**: each school has
      its own LMS database and the ids repeat, so parent 29 exists in every
      tenant. `lms_student_id` was deliberately NOT reused; a singular student
      pointer cannot express a parent paying for two children.
- [x] `ImportLmsGuardians` — preview then confirm, no file, following
      `OpeningBalanceController`'s shape minus the upload. De-duplication runs
      in confidence order: the source row's id, then email, then phone.

      The heuristics exist because **the LMS does not guarantee siblings share
      a parent row** — the demo data has `sm_students.id == sm_parents.id`
      with consecutive user ids, the signature of a record created per
      admission. A match merges; **ambiguity is refused, never guessed**,
      because merging two different people into one payer is far harder to
      unpick than importing nothing and saying why.
- [x] `pas_invoices.lms_student_id` + `student_name`. `contact_id` says who
      owes the money and cannot say who was taught; until now the only place a
      student could be named was the free-text `reference`, whose placeholder
      literally read "Student or PO reference". Nullable, because a school also
      bills organisations. `InvoiceRequest` refuses a payer not linked to the
      chosen student — the payer Select stays editable after a student is
      picked, so a stale selection can survive a change of student.
- [ ] Charges from a fee schedule. Deferred with nothing lost: **all 23
      transactional LMS fee tables are empty** in both tenant databases, and
      reading them would contradict §2 above. Invoice lines are still typed by
      hand. This is the largest remaining gap between here and "raise a term's
      invoices for a class", and it needs a decision about which system owns a
      fee schedule before it can be built.

#### Slice 12 — A school's logo on its documents
- [x] `pas_schools.logo_path` + `App\Services\SchoolLogo`, the app's **first
      stored upload**. Everything here is a precedent rather than a convention
      being followed, so anything that later accepts a file should copy it:
      the `public` disk, `schools/{id}/logo-{content-hash}.{ext}` (an immutable
      URL, so a replacement can never serve a stale image), and an extension
      derived from the **validated** mime, never from the client filename.
      **PNG and JPEG only, never SVG** — an SVG is a script-bearing document,
      and serving one back that a form accepted is stored XSS.
- [x] The logo enters both PDFs as a base64 `data:` URI, not a URL. There is no
      `config/dompdf.php`, so `enable_remote` is the vendor default **false**
      and dompdf refuses any `http(s)` `<img src>` *silently* — `Storage::url()`
      would have rendered nothing with no error anywhere. An absolute path
      would work for the invoice, which renders in-request, but the payslip
      renders in a queued job that cannot assume it shares a filesystem with
      the web node. One code path that cannot break either way, at ~33% on a
      file measured in tens of kilobytes.

      Sized with an explicit `height` and `width: auto`, because dompdf honours
      `max-height` unreliably: the first render came out at three times the
      intended size from the image's intrinsic pixels.
- [x] `dataUri()` returns **null** when the file is missing rather than
      throwing. A logo deleted off the disk must not take payroll's PDF
      generation down, and both templates already guard absent seller facts
      with `@if`.
- [x] `/admin/organisation` — the school's own settings, gated on a
      school-scoped role rather than platform-admin. It carries the logo plus
      `registered_name`, `tin` and `business_address`, which were **printed on
      the invoice face and the public pay page while being settable only by
      seeder or tinker**: they appear in no request class and no form, and
      `/admin/schools` is platform-admin only by design because those rows hold
      other schools' LMS credentials. A school could not correct its own
      letterhead. A blank file input means "keep the current logo", matching
      the blank-password and blank-secret rules already in the module.
- [x] The payslip **names the employer for the first time**. It carried neither
      name nor mark before — an employee keeping one had nothing on it saying
      who paid them. The field had to be added to **both** view models:
      `PayrollRunController::payslipViewModel()` and the deliberately duplicated
      `RenderPayslipPdfJob::buildViewModel()`, where missing one throws an
      undefined variable on the queued path only.
- [x] Which turned into a redesign, because dropping a logo onto the old layout
      only sharpened what was already wrong with it. The old payslip rendered
      the three money flows as three identical lists, which is why staff read
      employer contributions as a second set of deductions; it printed machine
      codes (`BASIC_PAY`, `SSS_EMPLOYEE`) at an audience that never types them;
      and it ran one 100%-wide column down an A4 sheet, leaving a canyon
      between every label and its figure.

      Now a narrow identity rail beside a money column, and the **direction of
      the money is the structural device**: `+ What you earned`, `− What was
      withheld from your pay`, `→ Paid for you, on top of your pay` — the last
      carrying the sentence that makes the employer block legible at all.

      The one new figure is `App\Support\ContributionLedger`: your share plus
      the school's share, per agency. Both halves are on every payslip in the
      country and the sum is on none of them, so the number that decides a
      salary loan or a benefit claim is the one staff have to work out by hand.
      Withholding tax is excluded — it is remitted but buys no entitlement.
      `tests/Feature/Payslips/PayslipDocumentTest.php` renders the Blade and
      reads it; the route tests only ever asserted the bytes start `%PDF-`.
- [x] **The invoice PDF now shares the payslip's design**, through
      `documents/partials/styles.blade.php` — masthead, identity rail, filled
      band for the figure the document exists to state, colophon at the page
      foot. What it deliberately does not borrow is the `+ − →` direction
      marks: money moves three ways on a payslip and one way on an invoice,
      and a structural device that encodes nothing is decoration.

      Reports keep their own `reports/partials/pdf-styles.blade.php`. They are
      worksheets read at a desk by someone reconciling figures and want
      density where these want a document that can be read once and trusted.

      It also fixed a display bug on the face of every invoice: dompdf sized
      the columns from their content and ran the unit price into the VAT rate,
      so "₱2,500.00" and "0%" printed as **"₱2,500.000%"**. Every column now
      carries an explicit width. `tests/Feature/Invoices/InvoiceDocumentTest.php`
      renders the Blade and reads it, as the payslip's does — the route tests
      only ever asserted the bytes were a PDF.
- [ ] **A payslip PDF is ~1.6 MB, and ~1.35 MB of that is fonts**, not content
      — dompdf embeds DejaVu Sans, Serif and Mono whole. At 100 payslips a run
      that is 160 MB of stored output. The logo accounts for 231 KB of it: a
      1024×1024 seal embedded to render at 52px, which a cached downscale in
      `SchoolLogo::dataUri()` would cut to near nothing. Neither is urgent;
      both are worth knowing before a school with 500 staff arrives.
- [x] **The on-screen payslip now carries the same document as the PDF** —
      same masthead, rail, direction marks, net band, ledger and colophon.

      The two drifted because `payslipViewModel()` promised to be the "single
      source of truth so the two surfaces never drift" while the page was
      handed only `run`, `payslip` and `employee`, and split `audit_lines`
      itself. It now receives the same `earnings` / `deductions` /
      `employerLines` / `contributions`, and `App\Support\PayslipLabel` is
      called by both so a line cannot be named one way on screen and another
      on the printout. Pinned by props assertions in
      `PayrollRunPayslipShowTest`.

      Two differences are the medium's, not a drift: the screen gets a
      cacheable logo **URL** where dompdf needs a base64 data URI (~300 KB
      that has no business in a page payload), and the date order is pinned
      to `en-GB` because `en-PH` formats a long date month-first and would
      disagree with the PDF's `j F Y`.
- [ ] `php artisan storage:link` is now a **real deploy step**, per environment.
      Without it the sidebar image 404s on every page while both PDFs still
      render, because they read bytes off the disk rather than fetching a URL —
      a failure that looks like a CSS bug. See §6 Deployment.

      The invoice email raises the cost of missing it: the logo there is a
      link, so an unlinked `storage/` gives every parent a broken image in a
      document about money. `APP_URL` matters for the same reason — the mail's
      logo URL is built from it, and an inbox cannot resolve a relative one.

#### Slice 13 — Recurring invoices
- [x] **A schedule is set up on the invoice form, not on its own page.**
      `/admin/recurring-invoices/create` and its `store` are gone, along with
      the create half of `recurring-invoice-form.tsx` — 711 lines of which ~62%
      was a copy of `invoice-form.tsx`, and the copy had already cost
      something: no student picker, no searchable payer combobox, no Notes or
      Terms, so `lms_student_id`, `notes` and `terms` sat in its form state and
      were silently always null on a schedule. Ticking **Repeat this invoice**
      now raises the draft AND the standing instruction from one typing.

      The cadence is the only thing the client sends. `day_of_month`,
      `starts_on` and `due_days` are derived by `StartInvoiceSchedule` from the
      invoice's own dates, so a schedule cannot disagree with the document it
      came from — and `day_of_month` between 1 and 31 stops being a rule to
      enforce because a 32nd is no longer expressible.

      **The first period is claimed at creation**, which is what stops the
      family being billed twice. `GenerateDueInvoices` derives where a schedule
      has got to from `periods()->count()` and never looks for an existing
      invoice, so a schedule starting on its own cadence day would otherwise
      have August raised again by the next catch-up run — three invoices for
      two months. Verified by removing the claim and watching the test go to 3.
      The seeded `next_run_on` keeps it out of `scopeDueOn` the same night;
      the claim is the guarantee, the cursor only keeps the run summary honest.
- [x] `pas_recurring_invoices` + template lines, a nightly
      `invoices:generate-recurring` (03:00 Manila), and an email that reaches
      the payer when a person approves what it raised. **Drafts only** — an
      unattended job never touches the ledger, never meets
      `AccountingPeriodGuard`, and never issues a claim against a parent.
- [x] The extraction that had to come first. Drafting an invoice lived in two
      *private* methods on `InvoiceController`, so a generator would have been
      a second way to build one. Now `CreateInvoiceDraft`, `InvoiceLineWriter`,
      `InvoiceHeaderAttributes` and `InvoiceBillingRules`, all shared with the
      controller. The billing rules mattered most: they lived only in the
      FormRequest, and a headless run has no form.
- [x] **`pas_recurring_invoice_periods` holds the never-bill-twice guarantee**,
      not a column on the invoice. The invoice's lifecycle is the wrong one:
      on the invoice, deleting a wrong draft releases the period and the job
      re-raises it that night, while voiding consumes it for good. A claim that
      outlives the document makes deletion safe and re-billing deliberate.
- [x] Four traps, each of which shipped a wrong invoice or none at all until a
      test caught it:
      **the run date** — the command resolves "today" in Manila, which is
      16:00 the previous day in UTC where the date casts live, so every
      schedule looked "not due" and the generator silently raised nothing;
      **`addMonth()` sticking** — 31 Jan → 28 Feb → 28 *Mar*, so `next_run_on`
      is recomputed from `starts_on` plus a period count;
      **period zero** — a schedule created on the 30th to bill on the 1st
      computed its first invoice as the 1st of that same month, in the past;
      **a stale tenant** — Spatie's `makeCurrent()` is a no-op when the same
      tenant is already bound, so the action read a stale `books_opened_on` and
      would happily backdate drafts nobody could approve.
- [x] `Schedule::useCache('redis')` and `withoutOverlapping(120)`. The
      scheduler resolves the *default* cache store, which `config/cache.php`
      sets to `database` — there is no `cache_locks` table and
      `MigrationSafetyTest` forbids adding one — and the default mutex is 24
      hours, so one OOM kill would skip a full day of billing.
- [ ] **Real SMTP is now a deploy prerequisite.** `MAIL_FROM_ADDRESS` is still
      `hello@example.com` and `MAIL_HOST` is `127.0.0.1`. The first send goes
      to actual parents; SPF/DKIM for the sending domain belongs on the same
      checklist.

      Note what that checklist does **not** have to cover, because of how the
      From line was settled: the school's *name* is the display name and the
      configured sender stays the address, so authentication is needed for one
      domain — this platform's — not for each school's. Sending as
      `office@stmarys.edu.ph` would need an SPF record in every tenant's DNS
      naming this host, a task no school will reliably do, and Gmail and Yahoo
      have binned unauthenticated bulk mail since 2024. `pas_schools.email`
      (new) carries `Reply-To` instead, so a parent's reply reaches the office
      without any of that. It is editable at `/admin/organisation`, beside the
      registered name and the logo, and empty simply means no `Reply-To`.
- [x] The invoice PDF attached, reversing Slice 13's "a link, not an
      attachment". The link still carries the paying and still dies when the
      document is voided; the PDF travels beside it because a parent asked to
      be sent an invoice expects one. **The trade is one-way per message**: the
      PDF holds the payer's name, address and TIN, and once it is in an inbox
      and on a relay, voiding withdraws the link and nothing else.

      `InvoicePdf` now builds the document for both the download and the mail —
      two call sites rendering the same view with their own paper size, logo
      rule and filename is two documents that drift, and the one nobody looks
      at is the one a customer receives. It also turns font subsetting back on
      per render: `barryvdh/laravel-dompdf` ships `enable_font_subsetting =>
      false`, overriding dompdf's own default, so every invoice was **1.38 MB
      against 32 KB** — invisible on a download, and the difference between an
      attachment that arrives and one a mail server refuses. Set per render
      rather than by publishing `config/dompdf.php`, whose other default
      (`enable_remote = false`) is what stops dompdf fetching remote images.

      The bytes are rendered in-request and carried on the mailable rather than
      built inside `attachments()` from an id: the attachment is then the
      document as it stood when the send was ordered, and a template failure is
      an error the operator sees rather than a queue job that dies having told
      nobody the invoice never went.
- [x] The school's logo in the email, linked rather than embedded — no
      attachment weight per message, and it resolves the same whether the send
      runs in-request or on a worker sharing no filesystem with the web node.
      `SchoolLogo` gained a third answer, `absoluteUrl()`: an inbox has no
      origin to resolve `/storage/...` against, and the disk's `url` config is
      absolute in every shipped environment but silently is not under
      `Storage::fake()` — a difference invisible until a parent opens the mail.

      `resources/views/vendor/mail/html/header.blade.php` is published **alone**
      so every other mail component stays on Laravel's upgrade path. The slot
      stays plain text: the markdown mailable renders a text half too, through
      `text/header.blade.php`, and an `<img>` in the slot would arrive as
      literal markup for anyone reading mail as text. Sizing is height-fixed
      with automatic width rather than the theme's hard 75x75 `.logo` square,
      which squashes a wordmark.
- [ ] **`schedule:run` must actually be wired up on the server.** Nothing
      depended on it before; from now on a school stops being billed if it is
      not, silently.

**Explicit non-goals for Phase 5:** bank feeds and reconciliation, multi-currency, budgeting,
fixed-asset depreciation schedules.

---

## 6. Cross-Cutting Concerns

These don't belong to a single phase — they are present throughout.

### Security
- HTTPS enforced in staging and production
- CSRF on all state-changing requests (Inertia handles)
- Sensitive fields encrypted at rest (TIN, SSS, PhilHealth, Pag-IBIG, bank account)
- Rate limiting on login (5/min per IP+email) and on bulk export endpoints
- Audit log entries include actor IP and user agent
- DB backups: daily automated, weekly manual verification, off-site copy
- **LMS data safety:** all writeable framework tables are prefixed (`pas_password_resets`, `pas_jobs`, `pas_failed_jobs`, `pas_notifications`, `pas_migrations`); LMS framework tables (`migrations`, `jobs`, `password_resets`, `notifications`, `oauth_*`) are never read or written by this app. `MigrationSafetyTest` enforces this with a positive `pas_*`-only allowlist on every migration and seeder.

### Testing strategy
- Backend unit tests for every Service public method, every Action, every Policy method
- Backend feature tests for every controller endpoint (happy path + most-likely failure)
- Frontend tests for `Money`, form validation handling, and critical user flows
- A "golden file" test suite for payroll computation: 20+ hand-computed scenarios that the engine must reproduce exactly. Run on every PR.

### Performance budgets
- TTI on the employee directory: < 1.5s on a cold cache, < 500ms warm
- Single-employee payroll preview: < 500ms server compute
- Batch payroll for 100 employees: < 2 minutes
- Bulk PDF for 100 payslips: < 5 minutes
- Report export (CSV) for one period of 100 employees: < 30 seconds

### Deployment steps that are not `migrate`
- **`php artisan storage:link`, once per environment.** Uploaded school logos
  live on the `public` disk and are served as static files. Without the symlink
  the sidebar image 404s on every page while the invoice and payslip PDFs still
  show the logo — they read the bytes off disk — so the symptom looks like a
  front-end bug rather than a missing deploy step.

### Observability
- Application logs to Laravel's stack driver; production also writes to a long-term store (Papertrail / CloudWatch)
- Failed jobs surfaced in **Horizon** (Redis-backed; aligned with the queue choice). Telescope in non-prod for general inspection.
- Health check endpoint for uptime monitoring

---

## 7. Acceptance Gates (Summary)

| Phase | Gate | Owner |
|---|---|---|
| End of Week 4 | Foundation & Employee Management demo | Client + Dev |
| End of Week 8 | Computation engine validated against reference cases | Client (HR/Finance) + Dev |
| End of Week 12 | Batch run + payslip + Excel import demo | Client + Dev |
| End of Week 16 | Production launch sign-off | Client |

Each gate requires written sign-off (email is fine) before the next phase begins. Schedule slips at a gate are surfaced before the next phase starts, not at the end of the project.

---

## 8. Dependencies on the Client

| Dependency | Needed by | Risk if late |
|---|---|---|
| Read-only LMS DB credentials | ✅ Already provisioned (default `mysql` connection points at `payroll_db`) | — |
| Sample employee export (≥ 20 records) | End of Week 1 | Test data gap; Phase 1 acceptance slips |
| Role matrix and approval workflow | End of Week 2 | Phase 1 RBAC delayed |
| Current statutory contribution tables (BIR, SSS, PhilHealth, Pag-IBIG) with effective dates | End of Week 4 | Phase 2 blocked |
| Hand-computed reference payroll cases (10+) | End of Week 5 | Phase 2 acceptance slips |
| Standardized payslip format mockup or reference | End of Week 9 | Phase 3 payslip work delayed |
| UAT participants and schedule | End of Week 13 | Phase 4 UAT compressed |
| Production environment access (DNS, SSL, DB) | End of Week 14 | Launch slips |

If any of these slip by more than one week, the corresponding phase shifts and a revised plan is issued.

---

## 9. Risks & Mitigations

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | Statutory contribution math wrong → underpayment to government | Low | High | Golden-file test suite with 20+ hand-verified cases; client HR/Finance signs off on engine in Week 8 |
| R2 | LMS schema changes mid-project, breaking the read-model | Medium | Medium | Read-model isolated in `App\Models\Lms` (extending `ReadOnlyModel`); integration tests run nightly against the live `payroll_db`, catching column renames or removals immediately; client commits to schema-freeze for the duration |
| R3 | Government rate change during build | Medium | Low | Effective-dated rate tables from day one; new rates added without code changes |
| R4 | Excel import scope creep (more sheets, more validation rules) | High | Medium | Define exact sheet structure in Week 9; out-of-scope additions go to v2 backlog |
| R5 | Bulk PDF generation exceeds queue worker memory | Medium | Medium | Stream-write PDFs; chunk into batches of 25; benchmark in Week 11 |
| R6 | Performance degrades past 500 employees | Low | Medium | Pagination + indexed queries; load-test at the end of Phase 3 with 1,000 seeded employees |
| R7 | Single-developer bottleneck (illness, holiday, focus loss) | Medium | High | Bi-weekly demos surface slippage early; documentation written as code is written, not at the end |
| R8 | Browser print rendering inconsistency for payslips | Medium | Low | Standardize on PDF export rather than relying on browser print for production payslips |
| R9 | Client approval workflow more complex than role matrix supports | Low | Medium | Lock workflow definition in Week 2; changes after that go through a change-request process |
| R10 | Audit log storage growth | Low | Low | JSON columns are efficient; partition `pas_audit_logs` by month if it crosses 10M rows (no action needed in v1) |
| R11 | LMS framework-table collision (shared DB writes corrupting LMS `migrations`/`jobs`) | Low | High | Framework tables prefixed (`pas_*`); cache/queue/sessions on Redis; `MigrationSafetyTest` positive allowlist; `App\Models\Lms\ReadOnlyModel` blocks runtime writes; `mysqldump payroll_db` before first deploy |

---

## 10. Open Questions

These are decisions deferred to early phases — listed here so they aren't forgotten.

1. **Pay frequency mix.** Are all employees on the same frequency, or does the company run both monthly and semi-monthly cycles?
2. **13th month pay.** Computed and disbursed within this system, or handled separately? (Strong recommendation: include — it's a small addition.)
3. **De minimis benefits.** Tracked here, or maintained outside payroll?
4. **Holiday pay rules.** Confirmed list of regular vs special non-working holidays for the contract year?
5. **Night-shift differential.** Applicable to any roles in this company?
6. **Email payslip distribution.** Default-on or opt-in per employee?
7. **PDF delivery.** Single archive (zip) for bulk, or per-employee email?
8. **Employee self-service portal.** Should employees log in to see their own payslips, or is distribution via email only? (Affects auth scope significantly — recommend deferring to v2.)
9. **Year-end annualization.** Hard requirement for v1, or a Phase 5 candidate? Current plan defers it.
10. **Backup payroll officer.** Two-person sign-off for approval, or single approver?

Decisions go in `docs/decisions/` as ADRs (Architecture Decision Records), one per question, dated and signed.

---

## 11. The Accounting Side — Now Phase 5 (In Progress)

The system is named "Payroll & Accounting" and **both modules ship from this codebase**. v1 (the 16-week timeline above) covers payroll only. The accounting half (general ledger, journal entries, chart of accounts, financial statements) is a **later phase of this same project**, not a separate codebase or external system.

**Status (2026-08-24): this is now Phase 5, and Slice 1 has shipped.** See the Phase 5 breakdown
in Section 5 above. Note that the three v1 groundwork promises listed below were **never actually
built** — verified absent on 2026-08-24 — so Slice 3 delivers them rather than building on them.

Why deferred:

- Building a credible GL within four months alongside payroll would compromise both.
- The full feature list for v1 focuses entirely on payroll computation, payslip generation, and reporting.

What v1 *will* do to make the accounting phase straightforward:

- Every approved payroll run produces a **structured posting payload** in JSON (the data needed to create journal entries: debit/credit pairs, account codes, period, references). This payload is stored on the run.
- A clean integration seam — `LedgerPostingService::post(PayrollRun $run)` — exists as a stub that the accounting module will implement.
- The chart-of-accounts mapping for payroll items (basic salary → account X, SSS employer share → account Y, etc.) is captured as a configurable lookup, not hardcoded.

When the accounting phase begins, it adds new app-owned tables in this same codebase:

- `pas_chart_of_accounts`, `pas_journal_entries`, `pas_journal_entry_lines`, `pas_ledger_postings`, etc. — all under the `pas_` prefix.

The LMS's empty `sm_chart_of_accounts`, `sm_add_incomes`, `sm_add_expenses`, `sm_bank_accounts`, `sm_fees_*`, `transcations`, `wallet_transactions` tables (and the dangling `payroll_payment_id` columns the LMS authors left in `sm_add_expenses` and `sm_bank_statements`) are explicitly **ignored**. We do not read from, write to, migrate, or seed any of them.

---

## 12. Timeline at a Glance

```
Month 1: Foundation & Employee Management
  W1  Project setup, theme, CI
  W2  DB schema, LMS bridge, auth + RBAC
  W3  Employee directory
  W4  Employee profile + inline editing            [Gate 1]

Month 2: Payroll Computation Engine
  W5  Government contribution tables
  W6  Computation engine (high risk)
  W7  Deductions, loans, allowances
  W8  Real-time gross-to-net UI                    [Gate 2]

Month 3: Batch Processing & Documents
  W9  Pay periods + payroll runs
  W10 Approval workflow + period locking
  W11 Payslip generation (PDF, bulk)
  W12 Excel import / export                        [Gate 3]

Month 4: Reports, Audit, Polish, Launch
  W13 Reports module
  W14 Audit log viewer
  W15 UAT, performance, bug fixes
  W16 Documentation, deployment, handover         [Gate 4 / Launch]

Week 17: Hypercare (post-launch, outside the plan)

Phase 5: Invoicing & Accounting (post-v1, see Section 5)
  S1  Ledger foundation — chart of accounts, tax rates, periods   [shipped]
  S2  Journal entries + balance/period invariants                  [shipped]
  S3  Payroll → GL posting seam (pays off the Section 11 debt)     [shipped]
  S4  Contacts                                                     [shipped]
  S5  Sales invoices (AR) — BIR numbering, VAT, PDF                [shipped]
      (credit notes + official receipts split out, pending Open Question 1)
  S6  Supplier bills (AP)                                          [shipped]
      (delivered by Slice 5's shared invoice table; Slice 7 added the
       sidebar entry that made bills reachable)
  S7  Payments & allocation                                        [shipped]
  S8  Financial reports
```

---

## 13. Living Document

This plan is the contract for v1 delivery. It will be updated as the project moves through each phase:

- After each gate, the corresponding section gets a "shipped" annotation noting any deferrals
- Risks that materialize move from Section 9 to a "Resolved" subsection with what happened
- Open questions get answered and migrated to ADRs

A diff of this file at the end of the project tells the full story of what changed and why.
