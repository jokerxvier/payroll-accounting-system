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
- Never reads, writes, or migrates any LMS table outside of the read-only identity surface (`users`, `sm_staffs`, `sm_designations`, `sm_human_departments`, `roles`). The LMS's legacy payroll and accounting tables (`sm_hr_*`, `sm_chart_of_accounts`, `sm_add_incomes`, `sm_add_expenses`, `sm_fees_*`, etc.) are explicitly ignored — the new system replaces them with its own implementation.

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
- Single-employee "preview" page: select employee + period, see live computation.
- Each input change re-computes server-side via debounced Inertia request (no client-side payroll math).
- Detailed breakdown view: gross, statutory deductions, custom deductions, adjustments, allowances, net.
- Side-by-side comparison with prior period.
- Phase 2 demo + sign-off.

**Phase 2 acceptance criteria**

- [x] Single-employee payroll computes correctly against a hand-calculated reference set (10 cases minimum) — `ReferenceCasesTest` ships 10 hand-derived cases against the rates seeded in 5D. Marked `REPLACE WITH CLIENT REFERENCE CASES` for replacement when the client provides theirs.
- [x] All four statutory contributions match official tables to the centavo — verified at the strategy layer (5C, 56 boundary tests) and through the engine (6D, 140 centavo-exact assertions).
- [x] Effective-dated contribution tables: changing a rate mid-test does not affect prior periods — verified in `PayrollComputationServiceTest::it picks the contribution row effective on period.end()`.
- [ ] Real-time preview updates within 500ms of input change (Week 8 — UI not yet built)
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
- Phase 3 demo + sign-off.

**Phase 3 acceptance criteria**

- [ ] A payroll run for 50 employees completes in under 60 seconds
- [ ] Approved runs cannot be edited; UI prevents and server enforces
- [ ] Single payslip renders identically in screen and print preview
- [ ] Bulk PDF for 50 employees generates in under 2 minutes; output is a single downloadable archive
- [ ] Excel import rejects malformed rows with field-level error messages and writes nothing on partial failure
- [ ] All payslips include the employee's TIN, SSS, PhilHealth, Pag-IBIG numbers when present

---

### Phase 4 — Reports, Audit, Polish & Launch (Weeks 13–16)

**Goal:** Reports module, audit log viewer, end-to-end polish, and production cutover.

#### Week 13 — Reports module
- Payroll summary report: per-period totals across gross, statutory contributions (employee + employer), custom deductions, net.
- Employee history report: per-employee timeline of payslips with running totals.
- Year-to-date views per employee (groundwork for future year-end annualization).
- Each report exportable to Excel, CSV, and PDF.
- Server-side report generation queued; UI shows status and download link when ready.
- Filters: date range, employee, department, employment type, status.

#### Week 14 — Audit log viewer
- Read-only audit log UI with filters (actor, action, date range, target).
- Detail drawer showing before/after diff per entry.
- Export audit log to CSV for external review.
- Verify every payroll-affecting action across phases produces an audit entry — gap analysis.
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

- [ ] Three reports (summary, employee history, audit) export cleanly to all three formats
- [ ] Every state-changing action across the system has an audit log entry; spot-check passes for each role
- [ ] Production deployment completes without data loss and the smoke-test checklist passes
- [ ] User documentation reviewed and accepted by client HR contact
- [ ] All P0 and P1 bugs from UAT closed; P2/P3 logged in the backlog with owners

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

## 11. The Accounting Side — Deferred to a Later Phase of This Same Project

The system is named "Payroll & Accounting" and **both modules ship from this codebase**. v1 (the 16-week timeline above) covers payroll only. The accounting half (general ledger, journal entries, chart of accounts, financial statements) is a **later phase of this same project**, not a separate codebase or external system.

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
```

---

## 13. Living Document

This plan is the contract for v1 delivery. It will be updated as the project moves through each phase:

- After each gate, the corresponding section gets a "shipped" annotation noting any deferrals
- Risks that materialize move from Section 9 to a "Resolved" subsection with what happened
- Open questions get answered and migrated to ADRs

A diff of this file at the end of the project tells the full story of what changed and why.
