# CODING_STANDARDS.md — Laravel Backend

Backend coding standards for the Payroll & Accounting system. Companion to `THEME.md` (visual design) and `RULES.md` (UI rules). This document covers Laravel, PHP, database, and architectural conventions.

**Stack:** Laravel 11 · PHP 8.3+ · PostgreSQL 16 · Inertia v2 · Pest · Pint.

---

## Table of Contents

1. [Architecture](#1-architecture)
2. [PHP & Laravel Conventions](#2-php--laravel-conventions)
3. [Database](#3-database)
4. [Money & Financial Data](#4-money--financial-data)
5. [Domain Integrity](#5-domain-integrity)
6. [Validation](#6-validation)
7. [Authorization](#7-authorization)
8. [Events & Auditing](#8-events--auditing)
9. [Inertia Responses](#9-inertia-responses)
10. [Testing](#10-testing)
11. [Performance](#11-performance)
12. [Security](#12-security)
13. [LMS Database Boundary](#13-lms-database-boundary)

---

## 1. Architecture

### Layered structure — mandatory

Every feature follows this layered architecture. No shortcuts.

```
app/
├── Models/                          # Eloquent models (data shape only)
├── Repositories/
│   ├── Contracts/                   # Repository interfaces
│   └── Eloquent/                    # Eloquent implementations
├── Services/                        # Business logic & orchestration
├── Actions/                         # Single-purpose write operations
├── Http/
│   ├── Controllers/                 # Thin HTTP adapters
│   ├── Requests/                    # FormRequest validation
│   └── Resources/                   # API response shaping (rare; Inertia first)
├── Policies/                        # Authorization
├── Events/                          # Domain events
├── Listeners/                       # Event handlers
├── Jobs/                            # Queued work (reports, exports, payroll runs)
├── Notifications/                   # Email / DB notifications
├── Enums/                           # PHP 8.1+ backed enums
├── ValueObjects/                    # Money, DateRange, AccountCode, etc.
└── Exceptions/                      # Domain-specific exceptions
```

### Layer responsibilities

| Layer | Responsibility | Forbidden |
|---|---|---|
| **Controller** | Receive request, authorize, call service/action, return Inertia response | Business logic, queries, validation logic |
| **FormRequest** | Validate, authorize, normalize input | DB writes, business decisions |
| **Service** | Orchestrate workflows, call repositories and actions, dispatch events | Direct queries (use repository), HTTP concerns |
| **Action** | Execute a single write operation atomically | Multiple unrelated operations, HTTP concerns |
| **Repository** | All Eloquent / query logic | Business decisions, validation |
| **Model** | Schema, casts, relationships, scopes (simple), accessors | Business logic, side effects, complex calculations |
| **Policy** | Authorization decisions | Anything else |
| **Value Object** | Encapsulate domain primitives (money, codes, periods) | Persistence, side effects |

### Service vs Action — when to use which

- **Action** — a single, atomic write operation that always does one specific thing.
  Examples: `PostJournalEntry`, `ApprovePayrollRun`, `VoidInvoice`, `RunPayrollForPeriod`.
- **Service** — orchestrates multiple actions, repositories, or branches based on domain logic.
  Examples: `PayrollService` (calls `RunPayrollForPeriod`, `GeneratePayslips`, `PostToLedger`).

If a class has more than one public method that mutates state, it's probably a Service. If it has exactly one `execute()` method, it's an Action.

### Repository pattern — the rules

- One interface per aggregate (`JournalEntryRepositoryInterface`) and one Eloquent implementation (`EloquentJournalEntryRepository`).
- Bind in `app/Providers/RepositoryServiceProvider.php`.
- Repositories return models, collections, paginators, or scalars. Never arrays.
- Repositories accept primitives, value objects, or filter DTOs. Never `Request` objects.
- Repositories do NOT call other repositories, services, or actions.
- Read methods only on repositories. **Write paths go through Actions** which use the repository's create/update/delete primitives.

```php
interface JournalEntryRepositoryInterface
{
    public function paginate(JournalEntryFilters $filters, int $perPage = 25): LengthAwarePaginator;
    public function find(int $id): ?JournalEntry;
    public function findOrFail(int $id): JournalEntry;
    public function create(array $attributes): JournalEntry;
    public function update(JournalEntry $entry, array $attributes): JournalEntry;
}
```

---

## 2. PHP & Laravel Conventions

### File header — every PHP file

```php
<?php

declare(strict_types=1);

namespace App\...;
```

`declare(strict_types=1)` is mandatory. CI rejects files without it.

### Type declarations

- Return types on every method and function. No exceptions.
- Parameter types on every parameter.
- Property types on every class property (PHP 7.4+).
- Use `readonly` for immutable properties (PHP 8.1+).
- Prefer union types over `mixed`. Use `mixed` only when truly indeterminate.
- `void` return for methods with no return value — never omit the type.

```php
public function approve(PayrollRun $run, User $approver): PayrollRun
{
    // ...
}

public function recordAuditTrail(string $action, array $context = []): void
{
    // ...
}
```

### Naming

| Item | Convention | Example |
|---|---|---|
| Class | `PascalCase`, singular | `JournalEntry`, `PayrollRun` |
| Interface | `PascalCase` + `Interface` suffix | `JournalEntryRepositoryInterface` |
| Method | `camelCase`, verb-first | `postEntry`, `findActive` |
| Variable | `camelCase` | `$payrollRun` |
| Constant | `UPPER_SNAKE_CASE` | `MAX_PAY_PERIODS_PER_YEAR` |
| Enum case | `PascalCase` | `PayrollStatus::Approved` |
| DB table | `snake_case`, plural | `journal_entries`, `payroll_runs` |
| DB column | `snake_case` | `posted_at`, `gross_amount_cents` |
| Route name | `kebab-case`, dot-separated | `payroll.runs.approve` |
| URI segment | `kebab-case`, plural | `/payroll-runs/{id}` |
| Config key | `snake_case` | `config('payroll.cutoff_day')` |

### Enums — always backed enums

Use PHP 8.1 backed enums for all closed sets of values. Persist the backing value, not the case name.

```php
declare(strict_types=1);

namespace App\Enums;

enum JournalEntryStatus: string
{
    case Draft     = 'draft';
    case Pending   = 'pending';
    case Posted    = 'posted';
    case Voided    = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::Draft   => 'Draft',
            self::Pending => 'Pending approval',
            self::Posted  => 'Posted',
            self::Voided  => 'Voided',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Posted, self::Voided], strict: true);
    }
}
```

Cast in models: `'status' => JournalEntryStatus::class`.

### Constructor property promotion — always

```php
final class PostJournalEntry
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly LedgerService $ledger,
        private readonly DispatcherContract $events,
    ) {}

    public function execute(JournalEntry $entry, User $actor): JournalEntry
    {
        // ...
    }
}
```

### `final` by default

- Mark Action and Service classes `final`. They are not designed for inheritance.
- Models, Controllers, FormRequests, and Policies can be non-final to allow framework hooks.
- Never extend an Action or Service — compose instead.

### Avoid facades in domain code

- Controllers, FormRequests, and tests may use facades (`Auth`, `DB`, `Log`, `Event`) for brevity.
- Services and Actions inject contracts: `DispatcherContract`, `DatabaseManager`, `LoggerInterface`, `AuthFactory`.
- This keeps services unit-testable without booting Laravel.

### Strings & arrays

- Single quotes by default. Double quotes only for interpolation.
- Use `sprintf` or `Str::of()` for complex strings, never repeated `.` concatenation.
- Use `array_*` functions or collections — never `for ($i = 0; ...)` over arrays.
- Spread operator (`...`) over `array_merge` for known keys.

### Collections vs arrays

- Repositories may return either. Document the choice in the interface.
- Inside services, prefer `Collection` for transformations.
- DTOs and serialized output: arrays.

---

## 3. Database

### Migrations

- One migration per logical change. Don't bundle unrelated tables.
- Always include `down()` for development, even if `--force` will be used in production.
- Use `decimal(15, 4)` ONLY when storing money as decimal (see Section 4 for the cents-based alternative).
- Always include `created_at` and `updated_at` (`$table->timestamps()`).
- Use `softDeletes()` ONLY for non-financial reference data (employees, accounts). NEVER on transactional financial records — those are voided, not soft-deleted.

### Foreign keys

- Always declare with cascade behavior:

```php
$table->foreignId('payroll_run_id')
    ->constrained('payroll_runs')
    ->restrictOnDelete();   // financial records — never cascade
```

- Use `restrictOnDelete()` for financial records — referential integrity is a feature, not a bug.
- Use `cascadeOnDelete()` only for tightly-owned child records (e.g., `payslip_line_items` belong to a `payslip`).

### Indexes

Add indexes for:
- Every foreign key (Laravel does NOT add these automatically in PostgreSQL)
- Every column used in `WHERE`, `ORDER BY`, or `JOIN`
- Composite indexes for multi-column filters: `(payroll_run_id, employee_id)`
- `posted_at`, `period_start`, `period_end` on transactional tables

### Naming — recap

- Tables: `snake_case`, plural — `journal_entries`, `payroll_runs`, `chart_of_accounts`
- Pivot tables: alphabetical singular pair — `account_user`, not `user_account`
- Columns: `snake_case`
- Foreign keys: `{singular}_id` — `employee_id`, `account_id`
- Boolean columns: `is_*` or `has_*` — `is_active`, `has_been_posted`
- Timestamp columns: `*_at` — `posted_at`, `approved_at`, `voided_at`

### Common columns on financial tables

Every financial transactional table includes:

| Column | Type | Purpose |
|---|---|---|
| `id` | bigint | PK |
| `reference` | string, unique | Human-readable ID (e.g., `JE-2026-001234`) |
| `period_id` | FK | Accounting period |
| `posted_at` | timestamp, nullable | Set on posting; null until posted |
| `posted_by` | FK to users, nullable | Who posted |
| `voided_at` | timestamp, nullable | Set on voiding |
| `voided_by` | FK to users, nullable | Who voided |
| `void_reason` | text, nullable | Required when voiding |
| `created_at` / `updated_at` | timestamps | Standard |

---

## 4. Money & Financial Data

This is the most important section. Get money wrong and nothing else matters.

### Storage — minor units (centavos), integer columns

Store all monetary amounts as **integer centavos** (PHP minor units), NEVER as float, NEVER as decimal in application code.

```php
$table->bigInteger('amount_cents');           // PHP centavos (1/100 PHP)
$table->bigInteger('gross_amount_cents');
$table->bigInteger('net_amount_cents');
```

Why:
- Floats are imprecise — `0.1 + 0.2 !== 0.3` in PHP and JS.
- Decimal columns work but every read requires manual care; integer math is trivially correct.
- `bigInteger` (8 bytes) supports up to ~92 quadrillion centavos — enough for any balance sheet.

### The `Money` value object

Wrap all money handling in a `Money` value object. Never pass raw integers around as "amount".

```php
declare(strict_types=1);

namespace App\ValueObjects;

final readonly class Money
{
    public function __construct(
        public int $cents,
        public string $currency = 'PHP',
    ) {}

    public static function fromCents(int $cents, string $currency = 'PHP'): self
    {
        return new self($cents, $currency);
    }

    public static function fromDecimal(string $decimal, string $currency = 'PHP'): self
    {
        // Use bcmath for input parsing
        $cents = (int) bcmul($decimal, '100', 0);
        return new self($cents, $currency);
    }

    public function plus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents + $other->cents, $this->currency);
    }

    public function minus(self $other): self
    {
        $this->assertSameCurrency($other);
        return new self($this->cents - $other->cents, $this->currency);
    }

    public function isZero(): bool      { return $this->cents === 0; }
    public function isNegative(): bool  { return $this->cents < 0; }
    public function isPositive(): bool  { return $this->cents > 0; }

    public function toDecimal(): string
    {
        return bcdiv((string) $this->cents, '100', 2);
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \DomainException("Currency mismatch: {$this->currency} vs {$other->currency}");
        }
    }
}
```

Cast in models:

```php
protected function casts(): array
{
    return [
        'amount_cents' => 'integer',
    ];
}

public function amount(): Money
{
    return Money::fromCents($this->amount_cents, $this->currency);
}
```

### Rounding

- Use `bcmath` (or `intdiv` for integer-only paths) for all monetary arithmetic.
- Banker's rounding (round half to even) for tax calculations: `round($value, 2, PHP_ROUND_HALF_EVEN)`.
- Standard half-up rounding for display only — never on the value being persisted.
- Document the rounding policy on every calculation.

### Currency

- Default and only currency is **PHP** for v1. Schema includes `currency` columns to allow future expansion.
- Store currency as ISO 4217 code (`PHP`, `USD`).
- `bigInteger` cents storage assumes 2 decimal places. JPY (0 decimals) or BHD (3) require schema awareness — out of scope for now.

### Forbidden — money

- `float` or `double` for any monetary value
- `decimal` columns combined with float casts in PHP
- Using `*=` / `+=` on raw amounts in PHP — wrap in `Money`
- Locale-aware number parsing in services — accept cents-as-int, format only at the edge
- Storing currency symbols (`PHP`, `₱`) in amount columns

---

## 5. Domain Integrity

### Double-entry accounting — invariant

**Every journal entry must balance: total debits === total credits, in cents, exactly.**

Enforce in three places:

1. **Database** — a check constraint or a posting trigger that rejects unbalanced entries.
2. **Action** — `PostJournalEntry::execute()` asserts balance before persisting.
3. **Test** — every code path that creates entries has an assertion.

```php
public function execute(array $lines, User $actor): JournalEntry
{
    $debits  = collect($lines)->sum(fn ($l) => $l['debit_cents']  ?? 0);
    $credits = collect($lines)->sum(fn ($l) => $l['credit_cents'] ?? 0);

    if ($debits !== $credits) {
        throw new UnbalancedJournalEntryException(
            "Debits ({$debits}) do not equal credits ({$credits})."
        );
    }

    // ... persist
}
```

### Period locks

Closed accounting periods are immutable. Once locked:

- No new entries can post to that period
- No existing entries in that period can be edited or voided (corrections require a reversing entry in an open period)
- Enforced in the Action layer, NOT just the UI

```php
if ($period->isLocked()) {
    throw new PeriodLockedException("Period {$period->code} is closed.");
}
```

### Idempotency

Operations that hit external systems (payroll disbursement, payment gateways) MUST be idempotent. Use an idempotency key on the request — duplicate requests return the original result, never re-execute.

```php
// payroll_disbursements has unique (idempotency_key) index
$disbursement = $this->disbursements->firstOrCreate(
    ['idempotency_key' => $key],
    [/* attributes */]
);

if ($disbursement->wasRecentlyCreated) {
    // First time — proceed with external call
} else {
    // Replay — return existing result
}
```

### Voiding instead of deleting

Financial records are NEVER deleted. They are voided.

- Add `voided_at`, `voided_by`, `void_reason` columns.
- Repository's read methods scope out voided records by default (`whereNull('voided_at')`).
- Voiding creates an audit trail entry.
- Voiding a posted entry creates a reversing journal entry — it does NOT mutate the original.

### Database transactions

Use `DB::transaction()` around any multi-write operation. The closure form handles rollback on exception automatically.

```php
public function execute(...): PayrollRun
{
    return DB::transaction(function () use (...) {
        $run = $this->runs->create([...]);

        foreach ($employees as $employee) {
            $this->createPayslip($run, $employee);
        }

        $this->ledger->postPayrollEntries($run);

        $this->events->dispatch(new PayrollRunApproved($run));

        return $run;
    });
}
```

Rules:
- Never start a transaction inside a controller — services and actions only.
- Don't dispatch events that trigger external HTTP calls inside a transaction (use queued listeners).
- Don't run long computations inside a transaction (precompute, then write).

### Time zones

- All timestamps stored in **UTC** (Laravel default).
- Display in **Asia/Manila** (UTC+8). Set `app.timezone` to `UTC`, format on the edge.
- Pay periods, cutoffs, and "today" use Asia/Manila — convert deliberately:

```php
$today = CarbonImmutable::now('Asia/Manila')->startOfDay();
```

Use `CarbonImmutable` over `Carbon`. Mutability is a bug magnet in financial code.

---

## 6. Validation

### FormRequest is the only place

All validation lives in `Http/Requests/`. Never validate in controllers, services, or models.

```php
declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\JournalEntryStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreJournalEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', \App\Models\JournalEntry::class);
    }

    public function rules(): array
    {
        return [
            'period_id'           => ['required', 'integer', 'exists:accounting_periods,id'],
            'memo'                => ['required', 'string', 'max:500'],
            'lines'               => ['required', 'array', 'min:2'],
            'lines.*.account_id'  => ['required', 'integer', 'exists:chart_of_accounts,id'],
            'lines.*.debit'       => ['nullable', 'numeric', 'gte:0'],
            'lines.*.credit'      => ['nullable', 'numeric', 'gte:0'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'lines.min' => 'A journal entry requires at least two lines (one debit, one credit).',
        ];
    }

    /** Normalize money inputs from decimal pesos to integer centavos. */
    protected function passedValidation(): void
    {
        $this->merge([
            'lines' => collect($this->input('lines'))->map(fn ($line) => [
                ...$line,
                'debit_cents'  => isset($line['debit'])  ? (int) bcmul((string) $line['debit'],  '100', 0) : 0,
                'credit_cents' => isset($line['credit']) ? (int) bcmul((string) $line['credit'], '100', 0) : 0,
            ])->all(),
        ]);
    }
}
```

### Money validation

- Accept `numeric` decimals from the user (frontend-friendly).
- Convert to integer cents in `passedValidation()` before passing to the service.
- Service signatures accept cents as `int` or a `Money` value object — never decimal strings.

### Authorization in FormRequest

`authorize()` returns the result of a Policy check. Don't duplicate logic in the controller.

---

## 7. Authorization

### Policy per model

Every domain model gets a Policy. Auto-discovery handles binding when conventions are followed.

```php
declare(strict_types=1);

namespace App\Policies;

use App\Models\JournalEntry;
use App\Models\User;

final class JournalEntryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('ledger.view');
    }

    public function view(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('ledger.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('ledger.create');
    }

    public function update(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('ledger.update')
            && $entry->status === \App\Enums\JournalEntryStatus::Draft;
    }

    public function post(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('ledger.post')
            && $entry->status === \App\Enums\JournalEntryStatus::Pending;
    }

    public function void(User $user, JournalEntry $entry): bool
    {
        return $user->hasPermissionTo('ledger.void')
            && $entry->status === \App\Enums\JournalEntryStatus::Posted
            && ! $entry->period->isLocked();
    }
}
```

### Permissions, not roles, in policies

Use `spatie/laravel-permission` (or equivalent). Policies check **permissions**, not roles. Roles are a UI concept for grouping permissions.

### Authorization layers

Defense in depth — check authorization at every layer:

1. **Route middleware** — `auth`, `verified`, `password.confirm` for sensitive ops
2. **FormRequest::authorize()** — policy check before validation
3. **Controller** — `$this->authorize('post', $entry)` for non-CRUD actions
4. **Service / Action** — re-check critical invariants (period not locked, entry not already posted)

The frontend never decides authorization. UI hides what the user can't do; the server enforces it.

---

## 8. Events & Auditing

### Domain events

Dispatch events for every significant state change:

```
JournalEntryDrafted
JournalEntrySubmittedForApproval
JournalEntryPosted
JournalEntryVoided
PayrollRunCreated
PayrollRunApproved
PayrollRunDisbursed
PayslipGenerated
PeriodClosed
```

Events are immutable past-tense facts. Listeners react to them.

### Audit trail — every write

Every financial mutation produces an audit log entry. Use a single `audit_logs` table:

| Column | Type |
|---|---|
| `id` | bigint |
| `auditable_type` | string (morph) |
| `auditable_id` | bigint (morph) |
| `event` | string (`created`, `updated`, `posted`, `voided`) |
| `actor_id` | FK users, nullable (system jobs are null) |
| `actor_type` | string (`user`, `system`, `import`) |
| `before` | jsonb |
| `after` | jsonb |
| `context` | jsonb (request ID, IP, reason) |
| `occurred_at` | timestamp |

Attach via a global event listener — don't sprinkle `AuditLog::create(...)` calls through services.

### Queued listeners for side effects

- Listeners that send email, hit external APIs, or generate PDFs implement `ShouldQueue`.
- Synchronous listeners only for in-process integrity (cache invalidation, audit logging).
- A failed external listener must NEVER roll back the originating transaction.

---

## 9. Inertia Responses

### Controller shape — thin

```php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreJournalEntryRequest;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\JournalEntryService;
use Inertia\Response;

final class JournalEntryController extends Controller
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly JournalEntryService $service,
    ) {}

    public function index(JournalEntryFilterRequest $request): Response
    {
        return inertia('JournalEntries/Index', [
            'entries' => $this->entries->paginate($request->toFilters()),
            'filters' => $request->validated(),
        ]);
    }

    public function store(StoreJournalEntryRequest $request): RedirectResponse
    {
        $entry = $this->service->draftEntry(
            $request->validated(),
            $request->user(),
        );

        return redirect()
            ->route('journal-entries.show', $entry)
            ->with('success', "Draft created · {$entry->reference}");
    }
}
```

### Inertia conventions

- Use the `inertia()` helper, not `Inertia::render()`.
- Page paths follow the domain folder: `JournalEntries/Index`, `Payroll/Runs/Show`.
- Pass minimal props — the page is not a place to ship the whole DB. Use `lazy()` for heavy props that aren't always needed.
- Filters round-trip via query string. Always pass back the validated filter set so the page can hydrate inputs.
- Flash messages use `with('success'|'error'|'warning'|'info', $message)`.

### Resources / DTOs

- For Inertia responses, prefer Eloquent's `toArray()` plus selective hiding/casting in the model.
- Reach for `JsonResource` only when the same data needs multiple shapes, or for genuine API endpoints.
- Money in Inertia responses goes out as **`{ cents: int, currency: string, formatted: string }`** — the frontend uses `formatted` for display and `cents` for any client-side math (rare).

```php
public function toArray(): array
{
    return [
        'id'        => $this->id,
        'reference' => $this->reference,
        'amount'    => [
            'cents'     => $this->amount_cents,
            'currency'  => $this->currency,
            'formatted' => $this->amount()->toDecimal(),
        ],
        'status'    => $this->status->value,
        'posted_at' => $this->posted_at?->toIso8601String(),
    ];
}
```

---

## 10. Testing

### Pest, not PHPUnit

All new tests use Pest. Features in `tests/Feature/`, units in `tests/Unit/`.

### Coverage expectations

- **Required:** every Action (`execute()`), every Service public method, every Policy method, every domain Event listener.
- **Required:** Feature test per controller endpoint covering happy path + the most likely failure (validation, authorization, period locked).
- **Optional but encouraged:** repository methods with non-trivial query logic.
- **Not required:** simple model accessors, factory definitions, framework boilerplate.

### Test structure

```php
<?php

declare(strict_types=1);

use App\Actions\PostJournalEntry;
use App\Enums\JournalEntryStatus;
use App\Exceptions\UnbalancedJournalEntryException;

it('posts a balanced entry and emits an event', function () {
    Event::fake();

    $entry = JournalEntry::factory()->pending()->create();
    $actor = User::factory()->canPost()->create();

    app(PostJournalEntry::class)->execute($entry, $actor);

    expect($entry->fresh())
        ->status->toBe(JournalEntryStatus::Posted)
        ->posted_by->toBe($actor->id)
        ->posted_at->not->toBeNull();

    Event::assertDispatched(JournalEntryPosted::class);
});

it('rejects an unbalanced entry', function () {
    $entry = JournalEntry::factory()->unbalanced()->create();

    expect(fn () => app(PostJournalEntry::class)->execute($entry, User::factory()->create()))
        ->toThrow(UnbalancedJournalEntryException::class);
});
```

### Database

- Use `RefreshDatabase` (or `LazilyRefreshDatabase` for the suite).
- SQLite in-memory is fine for unit tests; **integration tests use PostgreSQL** to catch collation, constraint, and JSONB differences.
- Factories provide named states for common scenarios: `->draft()`, `->pending()`, `->posted()`, `->voided()`, `->balanced()`, `->unbalanced()`.

### Money assertions

Compare cents, not decimals:

```php
expect($payslip->net_amount_cents)->toBe(124_530_050);   // PHP 1,245,300.50
```

Underscore separators are encouraged for readability on amounts.

### Time-sensitive tests

Use `Carbon::setTestNow()` or `travelTo()` — never sleep, never depend on real wall-clock.

```php
travelTo('2026-11-15 09:00', function () {
    // ... run pay period calculation
});
```

---

## 11. Performance

### Eager loading — the N+1 floor

- Every `index` action eagers what the table column or list item shows: `with(['employee', 'period'])`.
- Use `withCount()` for counts, never `$model->relation->count()` in a loop.
- Detect N+1 in development: `Model::preventLazyLoading(! app()->isProduction())` in `AppServiceProvider::boot()`.

### Pagination — always

- All index responses paginate. Default `25`, max `100`.
- Use cursor pagination for large append-only logs (audit logs, webhook events).
- Never `->all()` or `->get()` on potentially-large result sets in user-facing code.

### Queued work

Anything > 200ms of CPU or any external HTTP call goes to a queue:

- Payroll run computation
- Report generation (PDF, XLSX)
- Email / SMS / push notifications
- External API calls (payment gateways, government filings)

Use `ShouldQueue` interface, retry policies, and dead-letter handling.

### Caching

- Cache reference data (chart of accounts, tax tables, holiday calendar) via `Cache::rememberForever()` with versioned keys.
- Bust cache on relevant model events.
- Never cache user-specific or financially material query results.

---

## 12. Security

### CSRF

- All state-changing routes go through `web` middleware (CSRF protection).
- Inertia handles CSRF tokens automatically — don't disable.

### Mass assignment

- Always declare `$fillable` on models. Never use `$guarded = []`.
- Repositories receive validated data from FormRequests. Don't pass `$request->all()`.

### SQL injection

- Use parameter binding everywhere. Eloquent and the query builder do this by default.
- Never interpolate user input into raw SQL. If raw SQL is required, use `DB::raw()` with bindings.

### Sensitive data

- Government IDs (TIN, SSS, PhilHealth, Pag-IBIG), bank account numbers — encrypt at rest using `encrypted` cast.
- Salary information — restrict via Policy. Audit reads where appropriate.
- Never log sensitive fields. Use Laravel's `LogsActivity` exclusion list.

### Rate limiting

- Login: 5 attempts per minute per IP + email.
- Sensitive operations (export, bulk approve, period close): rate-limited per user.
- Use named limiters in `RouteServiceProvider`.

### Environment

- `.env` is never committed.
- `APP_DEBUG=false` in production. `APP_ENV=production`.
- `SESSION_SECURE_COOKIE=true`, `SESSION_SAME_SITE=lax`.
- HTTPS enforced via `URL::forceScheme('https')` in production.

---

## 13. LMS Database Boundary

This system attaches to the existing LMS database. Strict separation rules:

### Read-only access to LMS tables

- Tables NOT created or owned by this module are **read-only**.
- Use a dedicated read-only model with `protected $connection = 'lms';` if a separate connection is configured, OR a query scope that enforces the boundary.
- Never run migrations that alter LMS tables. New columns required for payroll go in payroll-owned tables and join via foreign key.

### Identifying ownership

- Payroll/accounting-owned tables are prefixed: `payroll_*`, `accounting_*`, `journal_*`, `chart_of_accounts`, `accounting_periods`, `tax_*`.
- LMS-owned tables: `users`, `students`, `staff`, `enrollments`, etc. Read-only.
- Shared: `users` is read-only for identity but extended via a `payroll_employee_profiles` table for payroll-specific fields.

### Data flow

- Pull employee identity from LMS (read).
- Mirror critical reference data (employee snapshot at time of pay run) into payroll tables to preserve historical accuracy when LMS records change.
- Write only to payroll/accounting tables.

### Shared models

- A read-only `Employee` model (or extended `User`) loads from LMS tables.
- Disable mutating methods: throw on `save()`, `delete()`, `update()`.

```php
abstract class ReadOnlyModel extends Model
{
    protected static function boot(): void
    {
        parent::boot();

        static::saving(fn () => throw new \LogicException('Read-only model cannot be saved.'));
        static::deleting(fn () => throw new \LogicException('Read-only model cannot be deleted.'));
    }
}
```

---

## Forbidden — backend

- `declare(strict_types=1)` missing
- Missing return types or parameter types
- Business logic in controllers, models, or FormRequests
- Direct queries (`User::where(...)`) outside repositories
- `float` / `decimal` for money in PHP
- `Model::all()` or `->get()` without pagination on user-facing endpoints
- `DB::beginTransaction()` outside services/actions
- Catching `\Exception` without re-throw or domain mapping
- Suppressing errors with `@`
- `dd()`, `dump()`, `var_dump()` in committed code
- Migrating LMS-owned tables
- Soft-deleting financial transactional records
- Editing or deleting records in closed periods

---

## Tooling — pre-merge gate

Every PR must pass:

```bash
./vendor/bin/pint --test         # formatting
./vendor/bin/phpstan analyse     # static analysis (level 8 minimum)
./vendor/bin/pest                # all tests
```

CI enforces. Local pre-commit hook recommended.

---

*This file is the contract for backend implementation. PRs that introduce backend changes must conform — or update this file together with the change.*
