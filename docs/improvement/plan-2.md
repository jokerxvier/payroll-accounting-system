# Multi-tenant payroll v2: physically separate payroll DB + dynamic LMS tenants

> **Status:** Phase A in progress (started 2026-05-07).
> **Supersedes:** `multi-tenant-payroll.md` (v1, 2026-05-07). v1 retained for historical reference.
> **Tooling:** `spatie/laravel-multitenancy` for tenant resolution, connection switching, queue + scheduler tenant binding (decision below). Phase A is independent of the tooling.
> **Estimated effort:** ~9–11 engineer-weeks of focused work, phased.

## Why this revision exists

v1 proposed centralized SaaS but assumed the auth pivot (its Phase 4) could ship after the tenant registry and dynamic connection switching. Three architectural promises crystallized after v1 landed, and they re-order the work:

1. **The payroll database is physically separate from every LMS database.** Today's `mysql` and `lms` connections both point at the same physical schema (LMS-owned). The future state has one central payroll DB and N independent LMS DBs (`lms_school_a`, `lms_school_b`, …). Cross-DB JOINs are impossible by construction; no shared physical schema.
2. **The LMS remains the identity master.** Passwords live in each LMS's `users` table. Payroll never owns the password. Users log into payroll with their existing LMS credentials.
3. **Each school is added through the admin UI, not by editing `.env`.** `pas_schools` stores per-tenant LMS connection config (encrypted). Super-admin onboards a new school via `/admin/schools` with a "test connection" button. No redeploy.

Once payroll DB is physically split from LMS DB, `App\Models\User` can no longer write Fortify state into the LMS-owned `users` table on the default `mysql` connection. **The auth pivot becomes a prerequisite for everything else** — that is v2's main re-ordering versus v1.

## Architecture promises (non-negotiable)

These belong at the top because every implementation decision downstream is constrained by them.

| # | Promise | Enforcement |
|---|---|---|
| 1 | Payroll DB is physically separate from every LMS DB | No cross-DB JOIN ever; JOINs only allowed *within* a single connection. The `EloquentEmployeeRepository` two-query strategy (paginate LMS, then `whereIn` on `pas_*`) is the canonical pattern. |
| 2 | LMS remains the identity master | Passwords are read from each tenant's LMS `users` row at login. Payroll never persists or rotates the password. |
| 3 | LMS users log into payroll with existing LMS credentials | First login auto-provisions a payroll role per `config/payroll.php`. No separate registration flow. |
| 4 | Per-tenant LMS credentials live encrypted in `pas_schools`, not in `.env` | `lms_db_password` cast as `'encrypted'`. Onboarding is an admin UI action; nothing requires deploy access. |
| 5 | The `lms` connection name stays — only its credentials become dynamic | `config('database.connections.lms')` is rebound per request from the resolved tenant's `pas_schools` row. `App\Models\Lms\*` never knows it's tenant-aware. |

## Tooling decision: `spatie/laravel-multitenancy`

We adopt Spatie's package for tenant resolution + connection switching + queue + scheduler tenant binding, **not** stancl/tenancy and **not** bespoke. Reasoning:

- The riskiest custom code in plan-2.md was queue tenant binding (Risk #5) and per-tenant scheduler loops (Risk #6). Spatie has both, battle-tested. Building these ourselves was ~200 lines of subtle queue middleware we'd likely ship with bugs the first time.
- Stancl/tenancy is overkill for our shape — it's designed for "platform provisions and owns the tenant DBs" SaaS. We're the inverse: payroll DB is central, LMS DBs are pre-existing and owned by the LMS app. Adopting stancl would mean disabling its default `CreatesDatabase`, `MigratesDatabase`, `SeedsDatabase`, and cache/filesystem bootstrappers — using ~25% of the package while fighting the rest.
- Spatie's surface in our codebase stays small: extend its `Tenant` base for `pas_schools`, write **one** custom task (`SwitchLmsConnection`) that rebinds only `database.connections.lms`, register its `NeedsTenant` middleware on web/api routes. Estimated **~80 lines of glue** vs ~600 lines if bespoke.

What stays bespoke (independent of Spatie):

- Phase A in its entirety (auth pivot to `pas_users`).
- Spatie permission **teams mode** (orthogonal to Spatie multitenancy).
- `/admin/schools` CRUD UI.
- School switcher in the header.
- Tenant context badge.

## Isolation guarantees (4 layers)

Each layer catches what the previous misses. A leak requires *every* layer to fail.

| Layer | Mechanism | What it isolates | Where it can leak |
|---|---|---|---|
| 1 | `ResolveTenant` middleware swaps `lms` connection to school A's DB | All LMS reads (`Staff`, `Department`, `Designation`, `Role`, `User`) | A request that bypasses middleware (rare; console only) |
| 2 | `BelongsToTenant` Eloquent global scope on every `pas_*` model | All payroll reads/writes (`EmployeeProfile`, `PayrollRun`, `Payslip`, …) | `Model::withoutGlobalScopes()` calls; raw `DB::table('pas_*')` queries |
| 3 | Spatie permission **teams mode** with `team_foreign_key = school_id` | Roles + permissions per school (the same `payroll-officer` exists per tenant) | Manual permission checks that ignore team context |
| 4 | Existing policies (`PayrollRunPolicy`, `EmployeeProfilePolicy`, …) | Maker-checker logic, automatically per-tenant once Spatie teams are on | Policies that bypass `Gate` (none today) |

### Where leaks can happen (audit checklist)

- `Model::withoutGlobalScopes()` — explicit override of the tenant scope. Acceptable only in: super-admin global reports, the school switcher, and the `pas_schools` registry CRUD itself.
- Raw `DB::table('pas_*')` queries — bypass Eloquent → bypass the global scope. Already rare in this codebase; an architecture test should enforce "no raw queries on `pas_*` tables outside repositories."
- **Background jobs** — no request, no resolved tenant. Every job touching tenant-scoped data must serialize `school_id` in its payload and bind `CurrentTenant` in `handle()`. `ComputeEmployeePayslipJob` and `RenderPayslipPdfJob` need this treatment.
- **Console commands / scheduled tasks** — same issue. The scheduler needs a `for each active school` loop wrapper.
- **The school switcher** — super-admin gate must be tight. Mistaking-school is the #1 multi-tenant ops bug.

## Authentication flow (multi-tenant, end-to-end)

```
User hits  ─►  ResolveTenant  ─►  swap `lms` connection
school-a.payroll.example.com   to school A's credentials
       │                                  │
       ▼                                  ▼
  POST /login                       Fortify reads
  email + password                  School A's LMS `users`
       │                                  │
       └──────────────►  verify password  ◄┘
                                │
                                ▼
                       upsert pas_users
                       (school_id, lms_user_id,
                        identity columns)
                                │
                                ▼
                AssignPayrollRoleOnLogin reads
                LMS `role_id` → maps via
                config/payroll.php → assigns
                Spatie role scoped to school A
                                │
                                ▼
                Subsequent requests authenticate
                via pas_users for session/Fortify state
```

**Open question — password resets.** Two options:

- **(a)** Payroll forwards the reset to the LMS (LMS still owns the password). Cleanest, but couples payroll to each LMS having a writable password-reset endpoint.
- **(b)** Document as out-of-band: "to reset your password, contact your LMS admin." Simpler; explicit; means payroll has no `/forgot-password` form once multi-tenant ships.

Pick before Phase A starts.

## Phased delivery (re-ordered, A–F)

Each phase is self-contained — ship behind `PAYROLL_MULTI_TENANT=false` so production keeps working in single-tenant mode while the foundation lands.

### Phase A — Auth pivot to `pas_users` (was v1 Phase 4) — 2 weeks

**Prerequisite for everything else.** Decouples `App\Models\User` from the LMS-owned `users` table.

- New migration `create_pas_users_table` on the payroll DB. Columns: `id`, `lms_user_id` (cross-reference back to LMS), `school_id` (nullable initially; populated in Phase B), `name`, `email`, `password`, `email_verified_at`, `remember_token`, `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at`, `created_at`, `updated_at`. Mirrors Fortify's expected schema.
- Move `App\Models\User` from `users` to `pas_users` on the default `mysql` connection. The model's `lmsWritableColumns` plumbing and `LmsWriteException` allowlist becomes irrelevant — drop it.
- Drop+recreate the 5 FKs from `pas_*` (statutory `voided_by`, payroll runs `submitted_by` / `approved_by` / `posted_by` / `voided_by`) to point at `pas_users(id)` instead of `users(id)`. These are now real same-DB FKs we can enforce.
- Single-tenant fallthrough seeder: copy existing rows from `users` (LMS) into `pas_users` (payroll), preserving `id` mapping. Reconciliation report: row count, FK integrity, role assignments preserved.
- `App\Listeners\AssignPayrollRoleOnLogin` unchanged in logic; just operates on the new table.
- The auth flow today still uses LMS `users` for password verification — that doesn't change in Phase A. Phase A only changes *where Fortify state is stored*. The LMS lookup still happens; it's the *write target* that moves.

**Tests:** full Fortify suite (login, register, password reset, 2FA setup/verify, email verification) — every code path that touches `User`. Manual smoke per role (super-admin, payroll-officer, hr, auditor, employee).

### Phase B — Tenant registry + admin UI (was v1 Phase 1) — 1–2 weeks

Establishes the schools taxonomy without changing runtime behavior.

- `composer require spatie/laravel-multitenancy`. Publish + adapt its config to `config/multitenancy.php`.
- New migration `create_pas_schools_table`. Columns: `id`, `slug` (URL-safe), `name`, **plus the columns Spatie's `Tenant` base expects** (per its docs), **plus** `lms_db_host`, `lms_db_port`, `lms_db_database`, `lms_db_username`, `lms_db_password` (cast `'encrypted'`), `lms_db_charset`, `is_active`, `created_at`, `updated_at`.
- New model `App\Models\Pas\School` **extends `Spatie\Multitenancy\Models\Tenant`**, with the encryption cast on `lms_db_password`. Override `getDatabaseName()` to return the LMS DB name (Spatie's tasks read this).
- Tenant finder: configure Spatie's `DomainTenantFinder` (subdomain default; path-prefix finder optional for local). Subclass if needed to map slug → school.
- New seeder `SchoolSeeder` — one default school for backwards compat (existing single-tenant data backfills to this school's `id`).
- Feature flag `PAYROLL_MULTI_TENANT=false` initially gates the `NeedsTenant` middleware so single-tenant behavior is preserved until Phase C goes live.
- Admin UI: `/admin/schools` index + create/edit (super-admin only). Connection-test button (opens a new connection with the form values, runs `SELECT 1`, reports success/failure without saving).
- Tests: `SchoolControllerTest` (CRUD + connection-test), `SchoolPolicyTest` (super-admin only).

**No behavior change yet** — payroll still uses the static `lms` connection from `.env`. The `NeedsTenant` middleware is registered but the tenant finder returns `null` until the flag flips in Phase C.

### Phase C — Dynamic LMS connection switching (was v1 Phase 2) — 1.5 weeks

The `lms` connection becomes per-request from the schools registry. **Spatie does the heavy lifting; we write one custom task.**

- Register Spatie's `NeedsTenant` middleware on web/api route groups in `bootstrap/app.php`. It runs the configured `TenantFinder`, calls `Tenant::makeCurrent()` on the resolved school, which fires the **Tasks pipeline**.
- Tenant finder: `Spatie\Multitenancy\TenantFinder\DomainTenantFinder` for subdomain strategy (production). For local / path-prefix dev, swap to a custom `PathTenantFinder` that reads `/schools/{slug}/...`.
- Custom task `App\Multitenancy\Tasks\SwitchLmsConnection implements SwitchTenantTask` (~30 lines). On `makeCurrent($tenant)`:
  - copy `lms_db_*` columns from the school into `config('database.connections.lms')`
  - `DB::purge('lms')` so the next query reconnects
  - on `forgetCurrent()`, restore the static config from `.env` (or null out — depends on whether console commands need a default).
- Configure `config/multitenancy.php`:
  - `tenant_finder` = our `DomainTenantFinder` subclass (or path finder for local)
  - `switch_tenant_tasks` = **only** `SwitchLmsConnection`. Spatie's default `SwitchTenantDatabaseTask` is **not** registered (we don't switch the default connection).
  - `queues_are_tenant_aware` = `true` — Spatie's `QueuesAreTenantAware` listener serializes the current tenant ID on dispatch and rebinds before `handle()`. Solves Risk #5 natively.
- LMS read-only safeguard preserved: `App\Models\Lms\ReadOnlyModel` still uses the connection name `'lms'`, just with dynamic credentials. `LmsWriteException` still fires on writes.
- Console commands gain `--tenant=slug` via Spatie's `tenants:artisan` wrapper. Scheduler uses Spatie's helper to iterate active schools — solves Risk #6 natively.

**Test infrastructure:**
- Spatie ships a `Tenant::makeCurrent()` test helper. Wrap it in `TestCase::actingAsTenant(School $school)` for ergonomics.
- A custom `RefreshesTenantConnection` trait isn't needed — `Tenant::forgetCurrent()` between tests is sufficient.

**Behavior change:** code that does `DB::connection('lms')->table(...)` now hits whichever school is current. Jobs and scheduled tasks **inherit** the current tenant via Spatie's queue + scheduler integration. No more per-job manual binding.

### Phase D — `school_id` on every payroll table + global scope (was v1 Phase 3) — 2–3 weeks

The hardest phase — the actual data isolation.

For every `pas_*` table that holds tenant-scoped data, add a `school_id` foreign key. **Tenant-scoped tables (15):**

```
pas_employee_profiles, pas_pay_periods, pas_payroll_runs, pas_payslips,
pas_employee_allowances, pas_employee_deductions, pas_employee_loans,
pas_payroll_adjustments, pas_audit_logs, pas_accounting_periods,
pas_jobs, pas_failed_jobs, pas_notifications, pas_password_resets,
pas_job_batches
```

**Globally-shared tables (NOT scoped per school):**

- `pas_allowances` — catalog of allowance types (e.g., "Rice Allowance"). Schools subscribe employees to allowances; the *subscription* (`pas_employee_allowances`) is per-school via the employee.
- `pas_deduction_types` — same shape.
- `pas_statutory_contributions` — SSS / PhilHealth / HDMF / BIR rate tables. Government rates are universal.

If a school operates abroad with different statutory rates, that's a v2 decision (escape hatch: add `school_id NULL` semantics where NULL = global, NOT NULL = override).

For each scoped table:

1. Migration `add_school_id_to_<table>` — `unsignedBigInteger school_id` nullable initially, FK to `pas_schools(id)`, indexed.
2. Backfill: one-time data migration fills `school_id` from the default school for any pre-existing data.
3. Follow-up migration changes the column to `NOT NULL`.

Eloquent layer:

- New trait `App\Concerns\BelongsToTenant` — applies global scope `WHERE school_id = CurrentTenant::id()`. Auto-fills `school_id` on the `creating` event.
- Apply the trait to every `App\Models\Pas\*` model that maps to a scoped table.

Spatie permission tables:

- Enable Spatie's **teams** feature in `config/permission.php`. Set `'teams' => true` and the team foreign key to `school_id`.
- Roles + permissions become per-school. The same `payroll-officer` role can exist for multiple schools without conflict.
- Migration to add `team_foreign_key` column on `roles` and `model_has_roles` per Spatie docs.
- `RoleSeeder` seeds the role taxonomy per school (one row per school × 5 roles).

**Tests:** every existing model/repository test gets an `actingAsTenant()` setup; expect ~50 tests of one-off churn.

### Phase E — UI surfaces (was v1 Phase 5) — 1 week

- **School switcher** in the header: super-admins switch tenant context (drops a session cookie, redirects to `{newslug}.payroll.example.com`). Non-super-admins are pinned to their school.
- **Tenant context badge** on every authenticated page: shows the current school name in the header. Mistaking-school is the #1 multi-tenant ops bug.
- **Sidebar gating** stays as is — existing role gates work per-school once Spatie teams are on.
- **Demo seeders** pick a default school (first by `id`) and seed within its scope. New artisan flag `--tenant=slug` runs the seeder against a specific school; `DemoPayrollSeeder` already wraps LMS reads defensively, so cross-tenant runs are safe.

### Phase F — Existing-customer cutover (was v1 Phase 6) — 1 week

Only relevant if existing customers run the single-tenant version.

- Per-school migration script: dumps `pas_*` rows from each school's shared DB, imports into the new central payroll DB with `school_id` filled.
- The existing LMS `users` data stays on each LMS DB (nothing to move).
- Cutover plan: maintenance window per customer, dump/import/verify, switch DNS or env to new central deploy.

## Critical files (cumulative across all phases)

**New files (~20):**

- `app/Models/Pas/School.php` (extends `Spatie\Multitenancy\Models\Tenant`) + factory + seeder
- `app/Multitenancy/Tasks/SwitchLmsConnection.php` (~30 lines, the only custom Spatie task)
- `app/Multitenancy/Finders/PathTenantFinder.php` (optional — local dev fallback if not using subdomain)
- `app/Concerns/BelongsToTenant.php`
- `app/Http/Controllers/Admin/SchoolController.php` + Inertia pages (`resources/js/pages/admin/schools/{index,form}.tsx`)
- `config/multitenancy.php` (Spatie's published config, adapted)
- `database/migrations/create_pas_users_table` (Phase A — lifted from v1 Phase 4)
- `database/migrations/create_pas_schools_table` (Phase B)
- `database/migrations/add_school_id_to_<table>` × 15 (Phase D)
- `database/seeders/SchoolSeeder.php`, `database/seeders/BackfillPasUsersSeeder.php` (Phase A.1)
- `tests/Feature/Tenancy/SwitchLmsConnectionTest.php`, `tests/Feature/Tenancy/BelongsToTenantTest.php`, `tests/Feature/Admin/SchoolControllerTest.php`

**Removed (vs v1's plan):**

- `app/Services/Tenancy/TenantResolver.php` — Spatie's `TenantFinder` replaces it
- `app/Services/Tenancy/CurrentTenant.php` — Spatie's `Tenant::current()` facade replaces it
- `app/Http/Middleware/ResolveTenant.php` — Spatie's `NeedsTenant` middleware replaces it

**Modified files (~50):**

- `app/Models/User.php` — connection moves to default, table becomes `pas_users` (Phase A)
- Every `app/Models/Pas/*` model — add `BelongsToTenant` (Phase D)
- Every existing test in `tests/Feature/` — add `actingAsTenant()` (Phase D)
- `composer.json` — add `spatie/laravel-multitenancy` dependency (Phase B)
- `config/database.php`, `config/permission.php`
- `app/Listeners/AssignPayrollRoleOnLogin.php` — operates on `pas_users` instead of `users` (Phase A); team-aware role assignment (Phase D)
- `app/Models/Lms/ReadOnlyModel.php` — same connection name, dynamic credentials (Phase C)
- `database/seeders/DemoPayrollSeeder.php` — accept `--tenant=` arg (Phase E)
- `routes/web.php`, `routes/settings.php` — wrap in Spatie's `NeedsTenant` middleware (Phase C)
- `bootstrap/app.php` — register `NeedsTenant` middleware (Phase C)

## Reused existing code (no new abstractions)

- **`spatie/laravel-multitenancy` package** — handles tenant resolution, connection switching, queue + scheduler tenant binding. We extend its `Tenant` base, write one custom task, register its `NeedsTenant` middleware. ~80 lines of glue.
- **Eloquent global scopes** — already used elsewhere (e.g., `notVoided` on payroll runs); apply same pattern for tenant.
- **Spatie permission teams feature** — built-in, just enable it. Orthogonal to Spatie multitenancy (different package).
- **The `lms` connection name infrastructure** — keep it; only the credentials become dynamic.
- **Two-query cross-connection strategy** in `EloquentEmployeeRepository::paginate()` — proven; remains the canonical pattern post-split.
- **Defensive try/catch around LMS reads** (already shipped at the dashboard, employees index, department dropdown, demo seeder) — generalizes naturally to "LMS for school A is unreachable" instead of the current "the single LMS is unreachable."
- **The 5 FK guards** (`Schema::hasTable('users')`) become unnecessary once auth moves to `pas_users` in Phase A — but they're not in the way until then.
- **`RefreshDatabase` test trait + the testing `users` mirror migration** — extend rather than replace. The mirror migration moves to mirror `pas_users` after Phase A.

## Breaking changes (existing data)

| Surface | Breakage | Mitigation |
|---|---|---|
| `App\Models\User` table | Auth moves to new `pas_users` (Phase A) | One-shot data import from existing `users` rows; reconciliation report |
| FKs from `pas_*` to `users.id` | Reference moves to `pas_users.id` (Phase A) | Drop+recreate FKs in Phase A migrations |
| `pas_*` tables | New required `school_id` column (Phase D) | Phase D backfill migration; nullable then NOT NULL |
| Spatie roles/permissions | Now scoped per school (Phase D) | Phase D enables teams mode + adds `team_foreign_key`; existing role rows backfilled to the default school |
| `DemoPayrollSeeder` | Requires `--tenant=slug` (Phase E) | Default-school fallback for backwards compatibility |
| Test fixtures | Need `actingAsTenant()` (Phase D) | One-off churn |
| URL structure | Subdomain or path-prefix becomes part of every URL (Phase C onward) | Inertia + Wayfinder regenerate route helpers; one-time but mechanical |
| `LmsWriteException` allowlist | Becomes irrelevant once Phase A ships | Drop the plumbing entirely; `LmsWriteException` itself stays as the read-only enforcement |

## Risks

1. **Login flow regressions** — Phase A is invasive. Every Fortify code path touches `User`. Mitigation: full Fortify suite + manual smoke per role + parallel-run period where both `users` and `pas_users` are kept in sync via observer, until confidence is high.
2. **Backfill correctness** — existing `users` → `pas_users` data import. Mitigation: reconciliation report (row count, FK integrity, role assignments preserved, hash equality on `password` column).
3. **Cross-DB JOINs are impossible** — already true today (rule from v1's docblock). After split, queries that today *coincidentally* work because of shared physical DB will fail. Audit: any explicit `JOIN sm_*` from a `pas_*` model. The `EloquentEmployeeRepository` two-query strategy is the model.
4. **Connection pool sizing** — with N tenants each opening an `lms` connection, MySQL connection limit becomes a real concern at scale. Mitigation: aggressive `DB::purge('lms')` at end of request, or pgBouncer-equivalent.
5. ~~**Background jobs** don't have a request → don't have a tenant.~~ **Mitigated by Spatie's `QueuesAreTenantAware`** — auto-serializes the current tenant ID on dispatch and rebinds before `handle()`. Just enable the listener in `config/multitenancy.php`. We verify with a Pest test that dispatches a job in tenant A's context, runs the worker, and asserts it ran in tenant A.
6. ~~**Cron / scheduled tasks** same issue.~~ **Mitigated by Spatie's scheduler helpers** — `tenants:artisan` console wrapper + `foreachTenant()` scheduler trait iterate active schools. We document the pattern for any per-tenant cron we add.
7. **Spatie teams gotcha** — enabling teams mode is a one-way migration. Test thoroughly before running in production.
8. **Forge/Cloud deployment** — needs to support wildcard subdomains (`*.payroll.example.com`) for the subdomain strategy. Verify with the chosen host before Phase C.
9. **Hardcoded role strings** (7+ files already noted in earlier work) become 7+ places where the tenant context is implicit. Fine since global scope handles it, but worth a sweep during Phase D.

## Verification (per phase)

Each phase ships its own test suite + a smoke checklist:

1. **Phase A** — `php artisan test --filter=Auth` is 100% green. Manual: log in as each demo role, password reset works, 2FA setup persists, email verification round-trips. Reconciliation report shows 0 row drift between old `users` and new `pas_users`.
2. **Phase B** — `php artisan db:seed --class=SchoolSeeder` creates rows; `/admin/schools` index renders for super-admin; "test connection" button correctly reports success/failure without saving.
3. **Phase C** — in tinker, `Tenant::current()` returns a `School` model after a request to `school-a.payroll.example.test`; `DB::connection('lms')->getDatabaseName()` reflects the resolved school. Pest test: dispatch a job under tenant A, assert worker rebinds tenant A in `handle()`.
4. **Phase D** — every existing Pest test still passes after applying the global scope; spot-check that two schools' data is fully isolated (school A's super-admin sees only school A's payroll runs even when school B has more rows).
5. **Phase E** — super-admin school switcher works; non-super-admin gets 403 trying to switch; tenant context badge renders the right school name on every authenticated page.
6. **Phase F** — dump/import script tested on a copy of production data; `assertNoDataLoss` reconciliation report.

## Recommendation

**Don't ship this in one PR.** Ship by phase, behind the feature flag.

Concrete rollout proposal:

- **Now**: ship Phase A. Production stays single-tenant (only one school exists, the default). The auth pivot is the prerequisite for everything else and pays for itself by removing the `users`-table coupling that already complicates dev/staging fresh-DB scenarios.
- **Quarter +1**: Phases B + C. Tenant registry + dynamic LMS connection. Production can now onboard new schools as separate deployments with cleaner config; centralized SaaS still off behind the flag.
- **Quarter +2**: Phase D. Full data isolation + Spatie teams mode. Migrate one pilot customer.
- **Quarter +3**: Phases E + F. UI polish + bulk migration of existing customers.

Total scope: ~10–13 engineer-weeks of focused work, plus client coordination for the migration window per customer.

## Out of scope (call out explicitly)

- **Per-school statutory contribution rates.** The catalog stays globally-shared; if a school operates abroad with different SSS/PhilHealth/etc., that's a v3 problem.
- **Per-school theme / branding.** Possible later but adds a lot of complexity.
- **Per-school payroll lifecycle customization.** The state machine stays uniform.
- **Cross-tenant reporting** (super-admin sees all schools at once). Reasonable v3; needs careful permission design.
- **Hot-failover between LMS DBs.** Not a tenancy feature; that's HA.
- **Migrating LMS data itself.** The LMS is the source of truth for identity — payroll never moves LMS rows.

## Open questions to resolve before Phase A starts

1. **Tenant identification strategy** — subdomain (`school-a.payroll.example.com`), path prefix (`/schools/school-a/...`), or both? Subdomain is cleaner; path prefix is friendlier to local dev. Recommended default: subdomain in production, path-prefix in local.
2. **Password reset semantics** — forward to LMS (option a) or document as out-of-band (option b)? Strongly recommend (b) for v2 simplicity; (a) can come later.
3. **Existing customers** — how many schools are currently live on the single-tenant model? If zero (greenfield), Phase F vanishes.
4. **Wildcard subdomain support** on the chosen host (Forge / Laravel Cloud) — verify before Phase C.
5. **Backfill window** — does the central payroll DB inherit existing `pas_*` data, or is multi-tenant a greenfield deployment? Affects Phase F scope materially.

## References (current codebase)

These files anchor each architectural promise. They are accurate as of 2026-05-07 and should be re-verified before Phase A starts:

- `app/Models/User.php` lines 17–37 — the docblock that anticipated the future split (still accurate, now actionable in Phase A)
- `app/Listeners/AssignPayrollRoleOnLogin.php` — the existing first-login → role mapping logic; preserved unchanged
- `config/payroll.php` — the LMS role allowlist; unchanged
- `app/Models/Lms/ReadOnlyModel.php` — the read-only contract; preserved
- `app/Exceptions/LmsWriteException.php` — read-only enforcement preserved; the `lmsWritableColumns` allowlist plumbing on `User` goes away in Phase A
- `app/Repositories/Eloquent/EloquentEmployeeRepository.php` — the two-query cross-connection pattern; canonical post-split
- `tests/Feature/LmsReadOnlyTest.php` — guardrail; still relevant
- `tests/Feature/MigrationSafetyTest.php` — guardrail; still relevant
- `bootstrap/app.php` — middleware registration site for `ResolveTenant`
- `config/database.php` — the `lms` connection definition; credentials become dynamic in Phase C
