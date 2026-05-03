# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Payroll system built on the Laravel React Starter Kit. Uses Laravel 13 + Inertia.js v3 + React 19 + TypeScript. Authentication is handled by Laravel Fortify (login, registration, 2FA, email verification, password reset). Served locally by Laravel Herd at `payroll-system.test`.

## Common Commands

```bash
# Development
composer run dev              # Starts artisan serve, queue, pail, vite concurrently
npm run dev                   # Vite dev server only

# Testing
php artisan test --compact                        # Run all tests
php artisan test --compact --filter=testName       # Run specific test
php artisan test --compact tests/Feature/Auth/     # Run test directory

# Code quality
vendor/bin/pint --dirty --format agent   # Format modified PHP files (run before finalizing)
npm run lint                             # ESLint fix
npm run format                           # Prettier fix
npm run types:check                      # TypeScript type checking
vendor/bin/phpstan analyse               # Larastan v3 — configured but NOT in ci:check; run manually
composer ci:check                        # Full gate: lint, format, types, tests (run before pushing)

# Frontend tests (Vitest)
npm test                                 # Run all Vitest tests
npm run test:watch                       # Watch mode

# Scaffolding (always pass --no-interaction)
php artisan make:model ModelName --no-interaction
php artisan make:test --pest TestName --no-interaction
php artisan make:controller ControllerName --no-interaction
```

## Architecture

### Backend (layered: Controller → FormRequest → Service → Repository → Model)
- **Controllers** in `app/Http/Controllers/` — grouped by domain (e.g., `Settings/`); thin, delegate to services
- **Form Requests** in `app/Http/Requests/` — mirror controller directory structure
- **Services** in `app/Services/` — business logic, organized by bounded context (`Payroll/`, `Statutory/`); strategy pattern for statutory contributions (`Statutory/Strategies/`)
- **Repositories** in `app/Repositories/` — split into `Contracts/` (interfaces) and `Eloquent/` (implementations); bound in service providers
- **Value Objects** in `app/ValueObjects/` — `Money` (integer centavos), `PayPeriodInput`; never use floats for money
- **Models** in `app/Models/` — `Pas/` for app-owned tables, `Lms/` for read-only LMS tables, `User.php` at root
- **Policies** in `app/Policies/` — one per model (per coding standards)
- **Concerns (Traits)** in `app/Concerns/` — shared validation rules (e.g., `ProfileValidationRules`, `PasswordValidationRules`)
- **Actions** in `app/Actions/` — Fortify action classes for user creation, password reset
- **Observers** in `app/Observers/` — model lifecycle hooks (e.g., audit logging)
- **Providers** — `FortifyServiceProvider` configures auth views; repository bindings live in their own provider
- **Middleware** — `HandleInertiaRequests` shares props globally; `HandleAppearance` manages theme

### Frontend
- **Pages** in `resources/js/pages/` — Inertia page components, auto-resolved by Vite
- **Layouts** in `resources/js/layouts/` — `AppLayout` (sidebar + header), `AuthLayout`, `SettingsLayout`
- **Components** in `resources/js/components/` — Radix UI primitives styled with Tailwind (shadcn/ui pattern), using `class-variance-authority` for variants
- **Hooks** in `resources/js/hooks/` — custom React hooks (`useAppearance`, etc.)
- **Types** in `resources/js/types/` — shared TypeScript interfaces
- **Wayfinder** — auto-generated typed route functions in `resources/js/wayfinder/`; import from `@/actions/` (controllers) or `@/routes/` (named routes). The Vite plugin regenerates these on `vite dev` / `vite build`. If you add a backend route and TypeScript can't resolve it from `@/routes/...`, restart `npm run dev` (or run `npm run build`) to regenerate the bindings.

### Routes
- `routes/web.php` — main routes (welcome, dashboard)
- `routes/settings.php` — settings pages (profile, security, appearance)
- Auth routes registered by Fortify automatically

### Database
- MySQL (`payroll_db`) — shared with the existing LMS (read-only via the `lms` connection)
- App-owned tables carry the `pas_` prefix; LMS tables (`sm_*`, `users`, `roles`, etc.) are read-only
- Redis-backed sessions, cache, and queue (configured to avoid colliding with LMS framework tables)
- Two guardrail tests enforce DB safety; do not weaken them: `tests/Feature/LmsReadOnlyTest.php` (no writes to LMS tables) and `tests/Feature/MigrationSafetyTest.php` (every migration is `pas_`-prefixed and reversible)
- Never run `migrate:fresh` against the dev DB — it drops the LMS tables. Use incremental `migrate`, or `--env=testing` for a clean slate

## Skills

Domain-specific skills are installed in `.agents/skills/` and `.claude/skills/`. Activate the relevant skill when working in that domain.

Always activate `/frontend-design:frontend-design` when creating or modifying frontend UI pages and components.

- `.agents/skills/shadcn/` — shadcn/ui CLI usage, component customization, composition patterns, styling rules, form patterns, icons, base vs Radix primitives
- `.agents/skills/vercel-react-best-practices/` — React performance rules: re-render optimization, async patterns, bundle size, hydration, server components, derived state, memoization
- `.agents/skills/git-commit/` — Git commit conventions

## Subagents

Project-specific subagents live in `.claude/agents/*.md` and are auto-loaded by Claude Code. They are the **preferred delegation targets** for this project — choose them over generic agents (`general-purpose`, `module-builder`, etc.) for any non-trivial task.

| Agent | Use it for | Tools |
|---|---|---|
| `project-manager` | "What's next?", scope checks, phase status, gate readiness, status summaries — read-only | Read, Grep, Glob, WebSearch |
| `backend-developer` | Migrations, models, repositories, services, actions, FormRequests, policies, controllers, jobs, Pest tests | Read, Write, Edit, Grep, Glob, Bash |
| `frontend-designer` | React pages, components, hooks, Zustand stores, TypeScript types, Tailwind/shadcn UI, Vitest tests | Read, Write, Edit, Grep, Glob, Bash |
| `qa` | Writing tests, verifying acceptance criteria, regression analysis, performance/N+1/accessibility/security audits | Read, Write, Edit, Grep, Glob, Bash |
| `git-expert` | Branches, commits, merges, rebases, conflicts, PRs, releases. Enforces "no Claude co-author trailer" | Read, Bash, Grep, Glob |

### Standard delegation flow

For most features:
1. **`project-manager`** confirms the work is in the current phase per `rules/PLAN.md`.
2. **`backend-developer`** ships migrations / models / services / Pest tests.
3. **`frontend-designer`** ships pages / components / Vitest tests on top.
4. **`qa`** verifies acceptance criteria, runs the guardrail tests (`LmsReadOnlyTest`, `MigrationSafetyTest`), and audits regressions.
5. **`git-expert`** commits and pushes with proper hygiene (no Claude co-author trailer).

For a bug fix: `qa` writes a failing test → `backend-developer` or `frontend-designer` fixes → `qa` re-verifies → `git-expert` commits.

If a task is genuinely trivial (single-line change, typo, rename within one file), do it directly without spawning a subagent. The bar is "would another set of eyes meaningfully improve this?" — if yes, delegate.

See `.claude/agents/README.md` for the full routing table and document hierarchy.

## Project Rules

Before making changes, read the relevant rule files in `rules/`. Also see `AGENTS.md` at the repo root for the agent-facing condensed handbook (delegation routing, guardrails, working agreements) — it overlaps with this file but is the canonical reference for non-Claude agents.

- `rules/PLAN.md` — 16-week phased delivery plan: scope and non-goals, phase breakdown (Foundation → Computation Engine → Batch Processing → Reports/Launch), acceptance gates, client dependencies, risk register, and what's deferred to v2 (general ledger, e-filing, year-end annualization)
- `rules/CODING_STANDARDS_LARAVEL.md` — Backend architecture: Repository + Service + Action layered pattern, money as integer centavos via `Money` value object, double-entry accounting invariants, domain integrity (period locks, voiding instead of deleting), `declare(strict_types=1)` on every PHP file, Policy per model
- `rules/CODING_STANDARDS_REACT.md` — Frontend architecture: TypeScript strict mode, Inertia patterns, component conventions, hooks, state management, forms, data fetching, routing, performance, error handling, testing
- `rules/RULES.md` — UI implementation rules: semantic color tokens only (never raw hex), `<Money>` component for all currency, `<PageHeader>` on every page, shadcn/ui composition patterns, financial table conventions, status badge variants
- `rules/THEME.md` — Visual design spec: warm cream/charcoal palette, Book Cloth orange accent, Inter/Source Serif 4/JetBrains Mono font stack, spacing rhythm, elevation levels, print styles

## Workflow Rules

### Track progress in PLAN.md
After finishing any task that appears in `rules/PLAN.md`, mark it as completed in that file before moving on. Use Markdown checkbox syntax (`- [x]`) for ticked items and (`- [ ]`) for outstanding items. Do this every time a task ships, not in batches at the end of a phase. The diff of `rules/PLAN.md` is the audit trail of what shipped.

### Git commit policy
Do NOT include a `Co-Authored-By: Claude` (or any other Claude/Anthropic) trailer in commit messages. Commits should be authored by the human user only. The default Claude Code commit-message template that adds the trailer must be omitted for this project.

### Delegate substantial work to project subagents
For any non-trivial task, prefer the project-specific subagents in `.claude/agents/` over generic ones. Match the task to the agent's domain (see the Subagents section above). When the routing isn't obvious, start with `project-manager` for a scope check; it will name the right next agent. Only do work directly when it's truly small (a typo, a single-line fix, an obvious rename) — anything that touches multiple files or multiple layers should be delegated.

### Register every new page in the sidebar
Every new authenticated Inertia page must have a corresponding entry in the sidebar nav. Add it to `mainNavItems` in `resources/js/components/app-sidebar.tsx`, importing the route helper from `@/routes/<resource>` (Wayfinder) and a Lucide icon. Public/auth pages (login, register, forgot password) and settings pages (already grouped under their own layout) are exempt. If a page should only appear for certain roles, gate it at the nav-item level via `auth.user` role checks rather than omitting it. The diff of `app-sidebar.tsx` is the audit trail of what's reachable.

### Never wrap pages in AppLayout
The global Inertia resolver in `resources/js/app.tsx` automatically wraps every authenticated page in `AppLayout`. Page components must NOT import or wrap with `<AppLayout>` themselves — doing so renders a second `SidebarProvider` inside the first, injecting a phantom 256 px gap that pushes all page content right by the sidebar width. The correct pattern is:

```tsx
export default function Page() {
    return (
        <>
            <Head title="..." />
            <div className="space-y-6 p-4">{/* content */}</div>
        </>
    );
}

Page.layout = {
    breadcrumbs: [{ title: '...', href: '...' }],
};
```

Settings pages are the only exception — they're routed through `[AppLayout, SettingsLayout]` and inherit the same rule (no manual `<AppLayout>` wrap). Reach for the static `Component.layout = { breadcrumbs }` to pass breadcrumbs into the resolved layout, mirroring `resources/js/pages/dashboard.tsx`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.3
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v3
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v3
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== herd rules ===

# Laravel Herd

- The application is served by Laravel Herd at `https?://[kebab-case-project-dir].test`. Use the `get-absolute-url` tool to generate valid URLs. Never run commands to serve the site. It is always available.
- Use the `herd` CLI to manage services, PHP versions, and sites (e.g. `herd sites`, `herd services:start <service>`, `herd php:list`). Run `herd list` to discover all available commands.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v3

- Use all Inertia features from v1, v2, and v3. Check the documentation before making changes to ensure the correct approach.
- New v3 features: standalone HTTP requests (`useHttp` hook), optimistic updates with automatic rollback, layout props (`useLayoutProps` hook), instant visits, simplified SSR via `@inertiajs/vite` plugin, custom exception handling for error pages.
- Carried over from v2: deferred props, infinite scroll, merging props, polling, prefetching, once props, flash data.
- When using deferred props, add an empty state with a pulsing or animated skeleton.
- Axios has been removed. Use the built-in XHR client with interceptors, or install Axios separately if needed.
- `Inertia::lazy()` / `LazyProp` has been removed. Use `Inertia::optional()` instead.
- Prop types (`Inertia::optional()`, `Inertia::defer()`, `Inertia::merge()`) work inside nested arrays with dot-notation paths.
- SSR works automatically in Vite dev mode with `@inertiajs/vite` - no separate Node.js server needed during development.
- Event renames: `invalid` is now `httpException`, `exception` is now `networkError`.
- `router.cancel()` replaced by `router.cancelAll()`.
- The `future` configuration namespace has been removed - all v2 future options are now always enabled.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== wayfinder/core rules ===

# Laravel Wayfinder

Use Wayfinder to generate TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `php artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `php artisan make:test --pest SomeFeatureTest` instead of `php artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `php artisan test --compact` or filter: `php artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
