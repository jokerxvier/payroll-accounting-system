# Multi-tenant payroll: 1 instance, N LMS schools

> **Superseded by [`plan-2.md`](./plan-2.md) on 2026-05-07.** Kept for historical reference.
> v2 re-orders the phases (auth pivot moves first because the payroll DB is now physically separate from every LMS DB) and adds explicit architecture promises + isolation guarantees up front.
>
> **Status:** Future improvement — captured 2026-05-07. Not scheduled.
> **Estimated effort:** 9–12 engineer-weeks of focused work, phased.

## Context

Today's architecture assumes **one payroll instance ⇆ one LMS database, sharing the same physical MySQL schema**. Concrete coupling points:

- `App\Models\User` (Fortify auth) targets the LMS-owned `users` table on the default `mysql` connection — writes `password`, `remember_token`, `email_verified_at` into a row that the LMS otherwise owns (`app/Models/User.php` lines 17–37).
- The `lms` connection (`config/database.php`) points at the same physical DB as `mysql`. Every `App\Models\Lms\*` model (`Staff`, `Role`, `Department`, `Designation`, `User`) reads through it.
- Five FK constraints from `pas_*` to `users(id)` exist (statutory void-by, payroll-runs approved-by/voided-by/submitted-by/posted-by). Already guarded with `Schema::hasTable('users')` for fresh-DB scenarios.
- `App\Listeners\AssignPayrollRoleOnLogin` reads LMS `role_id` off the user row and maps it to a Spatie payroll role (`config/payroll.php` lines 58–64).
- `DemoPayrollSeeder::ensureDemoProfiles()` reads `lms.sm_staffs` to onboard demo employees.

The User-model docblock already anticipates the future split: *"Both connections currently point at the same physical DB; a future physical split is a connection-string change with no app-code impact."* That's true for the connection itself but **not** for the rest of the architecture: the FKs, the tenant scoping, the auth flow, and the data isolation all need work.

The target deployment model is **Centralized SaaS**: one payroll deployment serves multiple schools, each with its own LMS database. One request comes in → tenant resolver picks the right school → all subsequent LMS reads route to that school's `lms` DB → all payroll writes carry that school's `school_id`.

## High-level architecture

```
                            ┌──────────────────────────┐
HTTP request  ───►  Tenant  │  AppServiceProvider sets │
(subdomain /        Resolver│  config('database.       │
 path / header)     Middlewa│  connections.lms') →     │
                            │  schools.{tenant}.lms_*  │
                            └──────────────────────────┘
                                       │
                                       ▼
                            ┌──────────────────────────┐
                            │  Eloquent global scope   │
                            │  on every Pas\* model:   │
                            │  WHERE school_id =       │
                            │     CurrentTenant::id()  │
                            └──────────────────────────┘
                                       │
                                       ▼
                            ┌──────────────────────────┐
                            │  Spatie team mode:       │
                            │  roles + permissions     │
                            │  scoped per school_id    │
                            └──────────────────────────┘

Storage layout:
   payroll_db (single, central)
      ├─ pas_*  (every row carries school_id)
      ├─ schools (registry of tenants + their LMS connection configs)
      └─ Spatie tables (with team_id = school_id)

   lms_school_a, lms_school_b, lms_school_c (one per tenant, untouched LMS schemas)
```

## Phased delivery

Each phase is self-contained — ship behind a feature flag (`PAYROLL_MULTI_TENANT=false` initially) so production keeps working in single-tenant mode while the foundation lands.

### Phase 1 — Tenant registry + config (1–2 weeks)

Establish the **schools** taxonomy without changing any runtime behavior.

- New migration: `create_pas_schools_table` — columns: `id`, `slug` (URL-safe identifier), `name`, `lms_db_host`, `lms_db_port`, `lms_db_database`, `lms_db_username`, `lms_db_password` (encrypted via Laravel's encrypter cast), `lms_db_charset`, `is_active`, `created_at`, `updated_at`.
- New model `App\Models\Pas\School` with `password` cast as `'encrypted'`.
- New seeder `SchoolSeeder` (one row per dev/staging school for testing).
- New config `config/multitenancy.php` — feature flag, identification strategy (subdomain | path | header), fallback school slug for legacy single-tenant fallthrough.
- Admin UI: `/admin/schools` index + create/edit forms (super-admin only). Lets ops register a new school by providing its LMS credentials. Connection-test button.

Tests: `SchoolSeederTest`, `SchoolPolicyTest` (super-admin only), `SchoolControllerTest` (CRUD + connection-test endpoint).

**No behavior change yet** — payroll still uses the static `lms` connection from `.env`.

### Phase 2 — Dynamic LMS connection switching (2 weeks)

Make the `lms` connection configurable per-request from the schools registry.

- New service `App\Services\Tenancy\TenantResolver` — given the current request, returns a `School` model (or null). Strategies (pluggable):
  - **Subdomain** (`{slug}.payroll.example.com`) — recommended default
  - **Path prefix** (`/schools/{slug}/...`) — useful for dev / single-domain ops
  - **Header** (`X-School-Slug: school-a`) — API clients
- New singleton `App\Services\Tenancy\CurrentTenant` — holds the resolved School for the request. App code reads via `app(CurrentTenant::class)->get()` or facade `Tenant::current()`.
- New middleware `App\Http\Middleware\ResolveTenant` — runs on every web/API request, resolves via `TenantResolver`, binds into `CurrentTenant`.
- Connection rebind: in `AppServiceProvider::boot` (or middleware), copy the resolved school's LMS credentials into `config('database.connections.lms')` and `DB::purge('lms')` so the next query reconnects.
- LMS read-only safeguard preserved: `App\Models\Lms\ReadOnlyModel` still uses the `lms` connection name, just with dynamic credentials.

Test infrastructure:
- `TestCase` adds `actingAsTenant(School $school)` helper.
- New trait `RefreshesTenantConnection` for tests that swap tenants mid-test.

Behavior change: code that does `DB::connection('lms')->table(...)` now hits whichever school is currently active. Code that doesn't run in a request context (jobs, console commands) needs explicit tenant binding before touching `lms`.

### Phase 3 — `school_id` on every payroll table + global scope (2–3 weeks)

The hardest phase — the actual data isolation.

For every `pas_*` table that holds tenant-scoped data, add a `school_id` foreign key. List (15 tables):

```
pas_employee_profiles, pas_pay_periods, pas_payroll_runs, pas_payslips,
pas_employee_allowances, pas_employee_deductions, pas_employee_loans,
pas_payroll_adjustments, pas_audit_logs, pas_accounting_periods,
pas_jobs, pas_failed_jobs, pas_notifications, pas_password_resets,
pas_job_batches
```

Globally-shared tables (NOT scoped per school): `pas_allowances`, `pas_deduction_types`, `pas_statutory_contributions`. These are catalog/config rows that apply identically to every tenant. (If a school wants its own statutory tables — e.g., overseas operation — that's a v2 decision.)

For each scoped table:

1. New migration `add_school_id_to_<table>` — `unsignedBigInteger school_id` nullable initially, with FK to `pas_schools(id)`. Indexed.
2. Backfill: a one-time data migration that fills `school_id` from a default school for any pre-existing data on the production DB. (For greenfield tenants, the column is populated at row creation.)
3. After backfill, a follow-up migration changes the column to `NOT NULL`.

Eloquent layer:
- New trait `App\Concerns\BelongsToTenant` — applies a global scope `WHERE school_id = ?` using `CurrentTenant::id()`. Auto-fills `school_id` on `creating` event.
- Apply the trait to every `App\Models\Pas\*` model that maps to a scoped table.
- Repositories already wrap queries cleanly — the global scope means existing repository methods automatically scope to the current tenant with no per-method changes.

Spatie permission tables:
- Enable Spatie's **teams** feature in `config/permission.php`. Set `'teams' => true` and set the team foreign key to `school_id`.
- Roles + permissions become per-school. The same `payroll-officer` role can exist for multiple schools without conflict.
- Migration to add `team_foreign_key` column on `roles` and `model_has_roles` per Spatie docs.
- The `RoleSeeder` seeds the role taxonomy per school (one row per school × 5 roles).

Tests: every existing model/repository test gets an `actingAsTenant()` setup; expect a one-off churn here (~50 tests).

### Phase 4 — Multi-LMS authentication (1–2 weeks)

The auth flow is the trickiest piece. Today's `User` model writes to LMS `users` directly. With one payroll DB and N LMS DBs, we need:

**Option A — Replicate users into payroll DB on first login (recommended)**
- On successful LMS auth, create/update a row in a new `pas_users` table on the payroll DB with the user's identity + their `school_id`.
- Fortify uses `pas_users` for all auth ops (login, password reset, email verification, 2FA).
- The `users` table on each LMS becomes read-only at the app boundary — nothing writes back.
- The login form prompts for school slug (if subdomain strategy isn't catching it) plus email + password. Auth flow: school_id → LMS connection rebind → query LMS users → verify password → upsert pas_users → login.

**Option B — Federated auth, write back to LMS users**
- Each request still writes Fortify state (password, email_verified_at) back to the resolved LMS's `users` table.
- Simpler in some ways but couples auth tightly to LMS uptime and creates the multi-LMS write headache for password resets.

Recommendation: **Option A**. Cleaner separation, one table to manage all auth state.

Decision either way:
- New migration `create_pas_users_table` with the columns Fortify needs + `school_id` + `lms_user_id` (the original LMS user.id for cross-reference).
- Migrate `App\Models\User` from `users` to `pas_users`.
- The `LmsWriteException` allowlist mechanism becomes irrelevant once we stop writing to the LMS table — drop the `lmsWritableColumns` plumbing.
- The 5 FKs from `pas_*` to `users(id)` change to `pas_users(id)` (now a real same-DB FK we can enforce).

### Phase 5 — UI surfaces (1 week)

- **School switcher** in the header: super-admins can switch tenant context (drops a session cookie, redirects to `{newslug}.payroll.example.com`). Non-super-admins are pinned to their school.
- **Tenant context badge** on every authenticated page: shows the current school name in the header so operators always know which tenant they're in. Mistaking-school is the #1 multi-tenant ops bug.
- **Sidebar gating** stays as is — the existing role gates work per-school once Spatie teams mode is on.
- **Demo seeders** pick a default school (the first one by `id`) and seed within its scope. Re-running them on a different school requires `--tenant=slug` flag (new artisan option).

### Phase 6 — Existing-data migration (1 week, only for live customers)

Only relevant if you have existing customers running the single-tenant version.

- Per-school migration script: dumps `pas_*` rows from each school's shared DB, imports into the new central payroll DB with `school_id` filled.
- The existing LMS `users` data stays on each LMS DB (nothing to move).
- Cutover plan: maintenance window per customer, dump/import/verify, switch DNS or env to new central deploy.

## Critical files (cumulative across phases)

**New files (~25):**
- `app/Models/Pas/School.php` + factory + seeder
- `app/Services/Tenancy/TenantResolver.php`
- `app/Services/Tenancy/CurrentTenant.php`
- `app/Http/Middleware/ResolveTenant.php`
- `app/Concerns/BelongsToTenant.php`
- `app/Models/User.php` rewrite (or new `pas_users` model — Phase 4)
- `app/Http/Controllers/Admin/SchoolController.php` + Inertia pages
- `config/multitenancy.php`
- `database/migrations/create_pas_schools_table` + 15 `add_school_id_to_*` migrations + `create_pas_users_table` (Phase 4)
- `database/seeders/SchoolSeeder.php`
- Tests: `SchoolControllerTest`, `TenantResolverTest`, `BelongsToTenantTest`, full sweep of existing tests for tenant context

**Modified files (~50):**
- Every `app/Models/Pas/*` model — add `BelongsToTenant`
- Every existing test in `tests/Feature/` — add `actingAsTenant()`
- `config/database.php`, `config/permission.php`
- `app/Listeners/AssignPayrollRoleOnLogin.php` — read from new `pas_users` table or per-tenant LMS
- `app/Models/Lms/ReadOnlyModel.php` — same connection name, dynamic credentials
- `database/seeders/DemoPayrollSeeder.php` — accept `--tenant=` arg
- `routes/web.php`, `routes/settings.php` — wrap in `ResolveTenant` middleware

## Reused existing code

- Eloquent global scopes — used elsewhere (e.g., `notVoided` on payroll runs); apply same pattern for tenant.
- Spatie permission **teams** feature — built-in, just enable it.
- The `lms` connection name infrastructure — keep it; only the credentials become dynamic.
- The 5 FK guards already in place (`Schema::hasTable('users')`) become unnecessary once auth moves to `pas_users` — but they're not in the way until then.
- `RefreshDatabase` test trait + the testing `users` mirror migration — extend rather than replace.

## Breaking changes (existing data)

| Surface | Breakage | Mitigation |
|---|---|---|
| `pas_*` tables | New required `school_id` column | Phase 3 backfill migration; nullable then NOT NULL |
| `App\Models\User` table | Auth moves to new `pas_users` | Phase 4 one-shot data import from existing `users` rows |
| FKs from `pas_*` to `users.id` | Reference moves to `pas_users.id` | Drop+recreate FKs in Phase 4 migrations |
| Spatie roles/permissions | Now scoped per school | Phase 3 enables teams mode + adds `team_foreign_key`; existing role rows backfilled to a default school |
| `DemoPayrollSeeder` | Requires `--tenant=slug` | Default-school fallback for backwards compatibility |
| Test fixtures | Need `actingAsTenant()` | One-off churn in Phase 3 |
| URL structure | Subdomain or path-prefix becomes part of every URL | Inertia + Wayfinder regenerate route helpers; one-time but mechanical |

## Risks

1. **Cross-DB JOINs are impossible.** Today some queries join `pas_employee_profiles` to LMS `sm_staffs` indirectly via repository code. After split, those joins become two queries (load IDs from one DB, hydrate from the other). Performance impact is small for the volumes involved.
2. **Connection pool sizing.** With N tenants each opening a `lms` connection, the MySQL connection limit becomes a real concern at scale. Mitigation: aggressive `DB::purge('lms')` at end of request, or pgBouncer-equivalent.
3. **Background jobs** don't have a request → don't have a tenant. Every job that touches `pas_*` or `lms` data must serialize the school_id and bind `CurrentTenant` on dispatch. The existing `ComputeEmployeePayslipJob` and `RenderPayslipPdfJob` need this treatment.
4. **Cron / scheduled tasks** same issue. The scheduler needs a "for each tenant" loop wrapper.
5. **The 7+ files with hardcoded role strings** (already noted in earlier work) become 7+ places where the tenant context is implicit. Fine since global scope handles it, but worth a sweep.
6. **Spatie teams gotcha**: enabling teams mode is a one-way migration. Test thoroughly before running in production.
7. **Forge/Cloud deployment**: needs to support wildcard subdomains (`*.payroll.example.com`) for the subdomain strategy. Verify with the chosen host.

## Verification (per phase)

Each phase ships its own test suite + a smoke checklist:

1. **Phase 1**: `php artisan db:seed --class=SchoolSeeder` creates rows; `/admin/schools` index renders for super-admin.
2. **Phase 2**: in tinker, `app(TenantResolver::class)->resolve(request())` returns a School model when given a subdomain request; `DB::connection('lms')->getDatabaseName()` reflects the resolved school.
3. **Phase 3**: every existing Pest test still passes after applying the global scope; spot-check that two schools' data is fully isolated (school A's super-admin sees only school A's payroll runs).
4. **Phase 4**: login as a school A user → password reset emails work → 2FA setup persists; same flow for school B; verify school A user can't log in via school B's URL.
5. **Phase 5**: super-admin school switcher works; non-super-admin gets 403 trying to switch.
6. **Phase 6**: dump/import script tested on a copy of production data; `assertNoDataLoss` reconciliation report.

## Recommendation

**Don't ship this in one PR.** Phase 1+2 (tenant registry + dynamic LMS connection) gets you most of the immediate value — the per-school deployment story works once those two phases are in, even before centralization. Phase 3 onward is the actual SaaS pivot.

Concrete proposal:
- **Now**: ship Phase 1 + Phase 2 behind `PAYROLL_MULTI_TENANT=false`. Production stays single-tenant. Lets you start onboarding new schools as separate deploys with cleaner config.
- **Quarter +1**: Phase 3 + Phase 4. Full data isolation + auth pivot. Migrate one pilot customer.
- **Quarter +2**: Phase 5 + Phase 6. UI polish + bulk migration of existing customers.

Total scope: ~9–12 engineer-weeks of focused work, plus client coordination for the migration window per customer.

## Out of scope (call out explicitly)

- Per-school **statutory contribution rates**. The catalog stays globally-shared; if a school operates abroad with different SSS/PhilHealth/etc., that's a v2 problem.
- Per-school **theme / branding**. Possible in v3 but adds a lot of complexity.
- Per-school **payroll lifecycle customization**. The state machine stays uniform.
- Cross-tenant reporting (super-admin sees all schools at once). Reasonable v2; needs careful permission design.
- Hot-failover between LMS DBs. Not a tenancy feature; that's HA.
- Migrating LMS data itself. The LMS is the source of truth for identity — payroll never moves LMS rows.

## Open questions to resolve before Phase 1 starts

1. **Tenant identification strategy** — subdomain (`school-a.payroll.example.com`), path prefix (`/schools/school-a/...`), or both? Subdomain is cleaner; path prefix is friendlier to local dev. Recommended default: subdomain in production, path-prefix in local.
2. **Auth flow shape (Phase 4)** — Option A (replicate users into `pas_users`) or Option B (federated, write-back to each LMS)? Strongly recommend A.
3. **Existing customers** — how many schools are currently live on the single-tenant model? If zero (greenfield), Phase 6 vanishes. If some, a real cutover plan is required.
