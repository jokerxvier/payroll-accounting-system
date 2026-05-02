# CODING_STANDARDS_REACT.md — Frontend

Frontend coding standards for the Payroll & Accounting system. Companion to:

- `THEME.md` — visual design specification
- `RULES.md` — UI rules + shadcn/ui best practices
- `CODING_STANDARDS.md` — Laravel backend standards

This document covers React, TypeScript, Inertia, state management, and frontend code organization. **For UI / visual / shadcn rules, read `RULES.md`.**

**Stack:** React 19 · TypeScript 5 (strict) · Inertia v2 · Zustand · TanStack Query · Vite · Vitest · Prettier · ESLint.

---

## Table of Contents

1. [Foundation](#1-foundation)
2. [File & Folder Structure](#2-file--folder-structure)
3. [TypeScript Conventions](#3-typescript-conventions)
4. [Inertia Patterns](#4-inertia-patterns)
5. [Component Patterns](#5-component-patterns)
6. [Hooks](#6-hooks)
7. [State Management](#7-state-management)
8. [Forms](#8-forms)
9. [Data Fetching](#9-data-fetching)
10. [Routing](#10-routing)
11. [Performance](#11-performance)
12. [Error Handling](#12-error-handling)
13. [Testing](#13-testing)
14. [Imports & Modules](#14-imports--modules)
15. [Code Quality](#15-code-quality)

---

## 1. Foundation

### Non-negotiables

- TypeScript strict mode. No `any`, no `@ts-ignore`, no `@ts-nocheck`.
- shadcn/ui is the only component library. (See `RULES.md` Section 6.)
- `lucide-react` is the only icon library.
- Tailwind utilities only. No inline styles, no CSS modules, no CSS-in-JS.
- Inertia `useForm` for all standard CRUD. No `react-hook-form` for routine forms.
- Ziggy `route()` for all URLs. No hardcoded paths.
- All currency rendering through `<Money>`. No `Intl.NumberFormat` in components.

### Always

- Prefer composition over configuration.
- Prefer derived state over stored state.
- Prefer explicit props over context where reasonable.
- Use `cn()` from `@/lib/utils` for conditional className merging.
- Type Inertia page props at the page component boundary.

### Never

- Use `any` to silence the compiler — narrow with `unknown` or define the type.
- Reach for global state when component state, derived state, or URL state would do.
- Manage server data in Zustand — use Inertia props or TanStack Query.
- Add a dependency without checking it isn't already covered by the existing stack.

---

## 2. File & Folder Structure

```
resources/js/
├── pages/                         # Inertia pages (one folder per domain)
│   └── JournalEntries/
│       ├── Index.tsx
│       ├── Show.tsx
│       ├── Create.tsx
│       └── Edit.tsx
├── components/
│   ├── ui/                        # shadcn primitives — do not edit casually
│   ├── money.tsx                  # Money renderer (project-wide)
│   ├── page-header.tsx            # Standard page heading
│   ├── stat-card.tsx              # KPI card
│   ├── status-badge.tsx           # Domain-state badge
│   ├── flash-listener.tsx         # Inertia flash → sonner bridge
│   └── journal-entries/           # Domain-scoped components
│       ├── journal-entry-form.tsx
│       ├── journal-entry-table.tsx
│       └── journal-entry-row-actions.tsx
├── hooks/
│   ├── use-debounced-callback.ts
│   ├── use-table-filters.ts
│   └── use-journal-entry-stats.ts
├── stores/
│   └── use-journal-entry-store.ts # Zustand stores (UI state only)
├── layouts/
│   ├── app-layout.tsx             # Authenticated app shell
│   └── auth-layout.tsx            # Login / register / password reset
├── lib/
│   ├── utils.ts                   # cn() and small generic helpers
│   ├── format.ts                  # formatPHP, formatDate, formatPercent
│   └── api.ts                     # axios instance for non-Inertia endpoints
├── types/
│   ├── inertia.d.ts               # Global Inertia PageProps
│   ├── ziggy.d.ts                 # Ziggy types (auto-generated)
│   ├── shared.ts                  # Cross-domain types (PaginatedResponse, Money)
│   └── journal-entry.ts           # Per-domain types
└── app.tsx                        # Entry point
```

### File naming

- **Components:** `kebab-case.tsx` for files, `PascalCase` for the export.
  `journal-entry-form.tsx` → `export function JournalEntryForm`.
- **Pages:** `PascalCase.tsx` (matches Inertia path). `Index.tsx`, `Show.tsx`, `Create.tsx`, `Edit.tsx`.
- **Hooks:** `kebab-case.ts`, prefix `use-`. `use-debounced-callback.ts`.
- **Stores:** `kebab-case.ts`, prefix `use-` and suffix `-store`. `use-journal-entry-store.ts`.
- **Types:** `kebab-case.ts`. `journal-entry.ts`.
- **Utility files:** `kebab-case.ts`. `format.ts`, `utils.ts`.

### One concept per file

- One component per file. The default export is the component.
- Sub-components used only by that component (e.g. internal `Row`, `EmptyState`) can live in the same file if they are truly local.
- A file with three or more components is a code smell — extract.

### Component placement rules

- **`components/ui/`** — shadcn primitives only. Edit only to add `cva` variants or wire new tokens (see `RULES.md`).
- **`components/`** (root) — project-wide composed components used across domains: `Money`, `PageHeader`, `StatCard`, `FlashListener`.
- **`components/{domain}/`** — domain-scoped components. Used only by pages in that domain.
- **`pages/`** — never imported from another page. Pages are leaves.

If a component crosses two domains, it belongs in `components/` root, not duplicated.

---

## 3. TypeScript Conventions

### Strict mode — non-negotiable

`tsconfig.json` requires:

```jsonc
{
  "compilerOptions": {
    "strict": true,
    "noUncheckedIndexedAccess": true,
    "noImplicitOverride": true,
    "noFallthroughCasesInSwitch": true,
    "exactOptionalPropertyTypes": true,
    "verbatimModuleSyntax": true
  }
}
```

CI fails on `tsc --noEmit` errors.

### `interface` vs `type`

- **`interface`** for object shapes representing entities, props, and public API surfaces.
- **`type`** for unions, intersections, mapped types, tuples, and primitives.

```ts
// GOOD
export interface JournalEntry {
  id: number;
  reference: string;
  status: JournalEntryStatus;
}

export type JournalEntryStatus = 'draft' | 'pending' | 'posted' | 'voided';

export type JournalEntryFilters = Pick<JournalEntry, 'status'> & {
  search?: string;
};
```

### No `any`. Ever.

If you don't know the type, use `unknown` and narrow:

```ts
// BAD
function parseError(err: any) {
  return err.message;
}

// GOOD
function parseError(err: unknown): string {
  if (err instanceof Error) return err.message;
  if (typeof err === 'string') return err;
  return 'Unknown error';
}
```

If a third-party library has weak types, write a `.d.ts` augmentation rather than scattering `any`.

### Type imports — always explicit

```ts
import { type JournalEntry, type JournalEntryFilters } from '@/types/journal-entry';
import { Button } from '@/components/ui/button';
```

`verbatimModuleSyntax` requires `type` imports for type-only references. This keeps the bundler from including dead code.

### Naming

| Concept | Convention | Example |
|---|---|---|
| Component | `PascalCase` | `JournalEntryForm` |
| Hook | `useCamelCase`, `use` prefix | `useTableFilters` |
| Store | `useCamelCase` + `Store` | `useJournalEntryStore` |
| Type / Interface | `PascalCase`, no `I` prefix | `JournalEntry`, not `IJournalEntry` |
| Enum-like union | `PascalCase` | `JournalEntryStatus` |
| Boolean variable | `is*` / `has*` / `should*` | `isLoading`, `hasErrors` |
| Event handler | `handle*` (local) / `on*` (prop) | `handleSubmit`, `onSubmit` |
| Ref | `*Ref` | `inputRef` |

### Props typing

Define props inline for trivial components, as a named `interface` for anything else:

```tsx
// Trivial — inline
export function Spinner({ size = 16 }: { size?: number }) {
  return <Loader2 className="animate-spin" style={{ width: size, height: size }} />;
}

// Standard — named interface
interface JournalEntryFormProps {
  mode: 'create' | 'edit';
  entry?: JournalEntry;
  periods: AccountingPeriod[];
}

export function JournalEntryForm({ mode, entry, periods }: JournalEntryFormProps) {
  // ...
}
```

Don't export every props interface — only when other code needs to compose with it.

### Discriminated unions

Use discriminated unions for variants. Never optional props that depend on each other.

```ts
// BAD — caller can pass invalid combinations
interface Props {
  mode: 'create' | 'edit';
  entry?: JournalEntry;     // required when mode === 'edit'
}

// GOOD
type Props =
  | { mode: 'create' }
  | { mode: 'edit'; entry: JournalEntry };
```

### Generic constraints

Use generics for genuinely reusable code (table, list, form). Don't reach for them when a simple union works.

```ts
interface DataTableProps<TRow extends { id: number }> {
  rows: TRow[];
  columns: ColumnDef<TRow>[];
}
```

### Branded types for domain primitives

Use branded types to prevent mixing IDs:

```ts
type Brand<T, B> = T & { readonly __brand: B };

export type JournalEntryId = Brand<number, 'JournalEntryId'>;
export type EmployeeId     = Brand<number, 'EmployeeId'>;
```

Apply at the boundary (where data enters the app) — Inertia props, API responses.

---

## 4. Inertia Patterns

### Page props typing

Type at the page component boundary. Define the props in `types/{domain}.ts`:

```ts
// types/journal-entry.ts
import type { PaginatedResponse } from '@/types/shared';

export interface JournalEntryIndexProps {
  entries: PaginatedResponse<JournalEntry>;
  filters: JournalEntryFilters;
  periods: AccountingPeriod[];
}
```

```tsx
// pages/JournalEntries/Index.tsx
import type { JournalEntryIndexProps } from '@/types/journal-entry';

export default function Index({ entries, filters, periods }: JournalEntryIndexProps) {
  // ...
}
```

### Shared data — typed once

Global Inertia shared data (`auth.user`, `flash`, `permissions`) is typed in `types/inertia.d.ts`:

```ts
// types/inertia.d.ts
import type { Config } from 'ziggy-js';

declare module '@inertiajs/core' {
  interface PageProps {
    auth: {
      user: User | null;
    };
    flash: {
      success?: string;
      error?: string;
      warning?: string;
      info?: string;
    };
    ziggy: Config & { location: string };
  }
}

export {};
```

Access via `usePage`:

```tsx
import { usePage } from '@inertiajs/react';

const { auth, flash } = usePage().props;
```

### Forms — `useForm`

```tsx
import { useForm } from '@inertiajs/react';

const { data, setData, post, processing, errors, reset } = useForm({
  reference: '',
  amount: 0,
});
```

See Section 8 for the full pattern.

### Navigation — `router` and `Link`

```tsx
import { router, Link } from '@inertiajs/react';

// Declarative
<Link href={route('journal-entries.show', entry.id)}>View</Link>

// Imperative
router.visit(route('journal-entries.index'), {
  preserveScroll: true,
});

// Inside a button — use asChild
<Button asChild>
  <Link href={route('journal-entries.create')}>New entry</Link>
</Button>
```

NEVER use `window.location` for navigation in an Inertia app — it triggers a full page reload and loses Inertia state.

### Partial reloads

For data updates that don't change the whole page, use `router.reload({ only: [...] })`:

```tsx
router.reload({
  only: ['entries'],
  preserveScroll: true,
});
```

### `preserveState` and `preserveScroll`

Default behavior wipes form state and scroll. Override when refreshing during a workflow:

- **Filters / search:** `preserveState: true`, `preserveScroll: true`, `replace: true`
- **After form save:** default (let Inertia redirect and reset)
- **Bulk action without page change:** `preserveState: true`, `preserveScroll: true`

### Polling / live updates

Use `router.reload()` on an interval, gated by visibility:

```tsx
useEffect(() => {
  if (document.visibilityState !== 'visible') return;

  const id = setInterval(() => {
    router.reload({ only: ['payrollRunStatus'] });
  }, 5000);

  return () => clearInterval(id);
}, []);
```

For high-frequency updates, prefer broadcasting (Pusher / Reverb) to a Zustand store, not polling.

---

## 5. Component Patterns

### Function components only

No class components. Use hooks for everything stateful.

### Composition over configuration

(See `RULES.md` Section 6 for the shadcn-specific version.)

```tsx
// GOOD
<JournalEntryDetail entry={entry}>
  <JournalEntryDetail.Header />
  <JournalEntryDetail.Lines />
  <JournalEntryDetail.AuditLog />
</JournalEntryDetail>

// BAD
<JournalEntryDetail
  entry={entry}
  showHeader
  showLines
  showAuditLog
  headerVariant="compact"
/>
```

### Props design

- 5 props or fewer is the comfortable ceiling. Beyond that, take an object.
- Boolean props default `false`. Naming: `disabled`, not `enabled`.
- Avoid `string` props with magic values — use union types.
- `children` for slot composition; named render props for parameterized rendering.

### Returning JSX from helpers

Helper functions that return JSX are components. Name them and call them as components, even if internal:

```tsx
// BAD
function renderRowActions(entry: JournalEntry) { return <DropdownMenu>...</DropdownMenu>; }
{rows.map(r => renderRowActions(r))}

// GOOD
function RowActions({ entry }: { entry: JournalEntry }) { return <DropdownMenu>...</DropdownMenu>; }
{rows.map(r => <RowActions key={r.id} entry={r} />)}
```

### Forwarding refs

Components that wrap a DOM element pass the ref through with `forwardRef`:

```tsx
import { forwardRef } from 'react';

interface CurrencyInputProps extends React.InputHTMLAttributes<HTMLInputElement> {
  // ...
}

export const CurrencyInput = forwardRef<HTMLInputElement, CurrencyInputProps>(
  ({ className, ...props }, ref) => (
    <Input
      ref={ref}
      inputMode="decimal"
      className={cn('text-right tabular-nums', className)}
      {...props}
    />
  ),
);

CurrencyInput.displayName = 'CurrencyInput';
```

`displayName` is required for React DevTools and is part of the shadcn convention.

### Conditional rendering

- Short conditions: `&&` is fine.
- Branching: ternary. Two branches max — extract a helper component for more.
- Long conditional content: extract to a sub-component or early return.

```tsx
// GOOD
{rows.length === 0 ? <EmptyState /> : <DataRows rows={rows} />}

// BAD — nested ternaries
{loading ? <Skeleton /> : error ? <ErrorState /> : rows.length === 0 ? <EmptyState /> : <DataRows />}

// GOOD — early returns
if (loading) return <Skeleton />;
if (error)   return <ErrorState error={error} />;
if (!rows.length) return <EmptyState />;
return <DataRows rows={rows} />;
```

### Keys

- Use stable IDs from your data (`entry.id`), never array index, never `Math.random()`.
- For composite lists (groups, sections), keys are unique within the parent, not globally.

---

## 6. Hooks

### Rules of hooks — reinforced

- Top level only. Never inside conditionals, loops, or nested functions.
- Same order every render.
- Custom hooks start with `use`.

### Custom hooks — when to extract

Extract a hook when:
- The same stateful logic appears in two or more components.
- A component has more than three `useEffect`s — group related ones into a hook.
- An effect is non-trivial (subscriptions, debouncing, polling).

Don't extract a hook just to "clean up" a single-use component — inline is clearer.

### `useEffect` discipline

- Every effect has a dependency array. No exceptions.
- The dependency array is exhaustive. `eslint-plugin-react-hooks` enforces.
- Effects clean up subscriptions, intervals, listeners. If you allocate it, you free it.
- Never use an effect for state that can be derived during render.

```tsx
// BAD — derived state in an effect
const [fullName, setFullName] = useState('');
useEffect(() => { setFullName(`${first} ${last}`); }, [first, last]);

// GOOD — derive during render
const fullName = `${first} ${last}`;
```

### `useMemo` and `useCallback` — use sparingly

Don't wrap everything. Use only when:
- The value is passed to a memoized child component.
- The computation is expensive (sorting large lists, parsing).
- The value is a dependency of another hook and you need referential stability.

Premature memoization is more common than performance issues.

### `useRef` — when

- DOM access (`inputRef.current.focus()`)
- Mutable values that don't trigger re-renders (timer IDs, previous values)
- Storing latest props/state for callbacks that shouldn't see stale values

Never read `ref.current` during render — it breaks Strict Mode guarantees.

### `useId` for accessibility

Generate stable IDs for label/input pairing, ARIA attributes:

```tsx
const id = useId();
return (
  <>
    <Label htmlFor={id}>Reference</Label>
    <Input id={id} />
  </>
);
```

### Common project hooks

- `useDebouncedCallback(fn, delay)` — for search inputs, filter changes
- `useTableFilters<T>(initial)` — sync filter state with URL
- `usePermission(name)` — check `auth.user.permissions` from Inertia shared data
- `useFlash()` — read latest flash message in a non-Inertia context

---

## 7. State Management

### Decision tree — where state lives

```
Is it server data?
├── YES → Inertia props (default) or TanStack Query (if you need cache/optimistic)
└── NO  → Is it UI state?
         ├── Used in one component? → useState
         ├── Used in a tree of related components? → lift to common parent or React Context
         └── Used across unrelated components? → Zustand store
```

### Inertia props — the default for server data

Most "state" is just Inertia props. The page rerenders when you visit, redirect, or `router.reload()` — no client store needed.

For filters/search, mirror them in URL query string and read back from props on every page load.

### Zustand — UI state only

Use for: modal open/close, multi-step wizard step, table selection, sidebar collapse, view preferences.

NEVER use for: lists from the server, current user, auth state, anything that has a server source of truth.

```ts
// stores/use-journal-entry-store.ts
import { create } from 'zustand';

interface JournalEntryUiState {
  selectedIds: number[];
  isCreateOpen: boolean;
  toggleSelected: (id: number) => void;
  openCreate: () => void;
  closeCreate: () => void;
  reset: () => void;
}

export const useJournalEntryStore = create<JournalEntryUiState>((set) => ({
  selectedIds: [],
  isCreateOpen: false,
  toggleSelected: (id) =>
    set((state) => ({
      selectedIds: state.selectedIds.includes(id)
        ? state.selectedIds.filter((i) => i !== id)
        : [...state.selectedIds, id],
    })),
  openCreate: () => set({ isCreateOpen: true }),
  closeCreate: () => set({ isCreateOpen: false }),
  reset: () => set({ selectedIds: [], isCreateOpen: false }),
}));
```

Rules:
- One store per domain. Don't lump unrelated UI state.
- Selectors for performance: `useStore((s) => s.value)` — components subscribe to slices, not the whole store.
- Reset on unmount (`useEffect(() => () => reset(), [])`) when state shouldn't persist across navigation.
- No async actions in stores. Async lives in components, hooks, or TanStack Query.

### TanStack Query — when Inertia isn't enough

Reach for TanStack Query when:
- You need client-side caching (e.g., dashboard widgets that share data across pages).
- You need optimistic updates with rollback.
- You need background refetching, stale-while-revalidate, or polling with deduplication.
- You're calling a non-Inertia API endpoint (export download URLs, third-party).

```ts
// hooks/use-journal-entry-stats.ts
import { useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';

export function useJournalEntryStats(periodId: number) {
  return useQuery({
    queryKey: ['journal-entry-stats', periodId],
    queryFn: async () => {
      const { data } = await api.get(route('api.journal-entries.stats', periodId));
      return data;
    },
    staleTime: 60_000,
  });
}
```

Rules:
- Query keys are arrays. First segment is the resource name; subsequent segments are filters/IDs.
- Mutations invalidate related queries via `queryClient.invalidateQueries()`.
- Disable retry on 4xx errors — only 5xx retries make sense for data fetches.

### React Context — when neither

Use Context for:
- Theme / appearance toggle
- Localization
- Auth (we get this from Inertia props, but Context is reasonable for derived auth helpers)
- Tightly-scoped state shared across a single feature tree

Don't use Context for high-frequency updates (every render of every consumer). Zustand handles that better.

---

## 8. Forms

### `useForm` is the default

For all standard CRUD, use Inertia's `useForm`. Validation is server-side via `FormRequest`.

```tsx
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Loader2 } from 'lucide-react';
import type { FormEvent } from 'react';
import type { JournalEntry } from '@/types/journal-entry';

interface JournalEntryFormProps {
  mode: 'create' | 'edit';
  entry?: JournalEntry;
}

export function JournalEntryForm({ mode, entry }: JournalEntryFormProps) {
  const { data, setData, post, put, processing, errors, isDirty } = useForm({
    reference: entry?.reference ?? '',
    memo: entry?.memo ?? '',
  });

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault();
    if (mode === 'create') {
      post(route('journal-entries.store'));
    } else {
      put(route('journal-entries.update', entry!.id));
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="reference">Reference</Label>
        <Input
          id="reference"
          value={data.reference}
          onChange={(e) => setData('reference', e.target.value)}
          aria-invalid={!!errors.reference}
        />
        {errors.reference && <p className="text-sm text-destructive">{errors.reference}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="memo">Memo</Label>
        <Textarea
          id="memo"
          value={data.memo}
          onChange={(e) => setData('memo', e.target.value)}
          aria-invalid={!!errors.memo}
        />
        {errors.memo && <p className="text-sm text-destructive">{errors.memo}</p>}
      </div>

      <Button type="submit" disabled={processing || (mode === 'edit' && !isDirty)}>
        {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
        {mode === 'create' ? 'Create' : 'Save changes'}
      </Button>
    </form>
  );
}
```

### Form rules

- Errors live in `errors.{field}` and render directly under the field.
- Submit button is disabled while `processing`.
- Edit forms additionally disable submit when `!isDirty`.
- Every input is paired with `<Label htmlFor>` — for accessibility AND `useId`.
- `aria-invalid={!!errors.field}` for screen readers.
- Reset on success via `onSuccess: () => reset()` if the form stays mounted.
- Confirm before navigating away from a dirty form (`onBefore` Inertia event or `useBeforeUnload`).

### Money inputs

Use `<Input inputMode="decimal">`, NEVER `<Input type="number">`. (See `RULES.md` Section 5.)

```tsx
<Input
  inputMode="decimal"
  pattern="[0-9]*\.?[0-9]*"
  className="text-right tabular-nums"
  value={data.amount}
  onChange={(e) => setData('amount', e.target.value)}
/>
```

The backend converts decimal pesos → integer cents in `FormRequest::passedValidation()` (see `CODING_STANDARDS.md` Section 6). The frontend just passes the string through.

### Dynamic field arrays

For repeating sections (e.g., journal entry lines):

```tsx
const { data, setData } = useForm({
  lines: [
    { account_id: '', debit: '', credit: '', description: '' },
  ] as Line[],
});

const addLine = () =>
  setData('lines', [...data.lines, { account_id: '', debit: '', credit: '', description: '' }]);

const removeLine = (i: number) =>
  setData('lines', data.lines.filter((_, idx) => idx !== i));

const updateLine = (i: number, patch: Partial<Line>) =>
  setData('lines', data.lines.map((line, idx) => (idx === i ? { ...line, ...patch } : line)));
```

For complex array forms (drag-and-drop reordering, nested arrays), reach for the shadcn `<Form>` primitive (react-hook-form). See `RULES.md` Section 6.

### File uploads

Use `useForm` with `forceFormData: true`:

```tsx
const { data, setData, post } = useForm({
  attachment: null as File | null,
});

post(route('attachments.store'), { forceFormData: true });
```

---

## 9. Data Fetching

### Inertia first

Inertia covers ~95% of data needs. Page navigation pulls fresh data; `router.reload({ only: [...] })` refreshes a slice.

### Axios for non-Inertia endpoints

Use the configured instance from `@/lib/api` — never raw `fetch` or new axios instances:

```ts
// lib/api.ts
import axios from 'axios';

export const api = axios.create({
  baseURL: '/',
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
    Accept: 'application/json',
  },
});

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 419) {
      // Session expired — full reload to refresh CSRF token
      window.location.reload();
    }
    return Promise.reject(error);
  },
);
```

### Use TanStack Query for cached async

(See Section 7.) Don't manage `isLoading` / `error` / `data` manually with `useState` + `useEffect`. TanStack Query handles all of that correctly.

### Forbidden

- `fetch()` directly — use the `api` instance
- Multiple axios instances with different config
- `useEffect` for async data fetching (use TanStack Query)
- Storing fetched data in Zustand

---

## 10. Routing

### Ziggy `route()` for all URLs

```tsx
import { Link } from '@inertiajs/react';

// GOOD
<Link href={route('journal-entries.show', entry.id)}>View</Link>
<Link href={route('journal-entries.index', { status: 'pending' })}>Pending</Link>

// BAD — hardcoded
<Link href={`/journal-entries/${entry.id}`}>View</Link>
```

### Route names mirror the backend

The backend defines names (`journal-entries.index`, `payroll.runs.approve`); frontend uses them verbatim. Run `php artisan ziggy:generate` after route changes — the generated file is committed.

### Programmatic navigation

```tsx
import { router } from '@inertiajs/react';

router.visit(route('journal-entries.index'));
router.get(route('journal-entries.index'), { status: 'pending' });
router.post(route('journal-entries.approve', entry.id), {}, {
  preserveScroll: true,
  onSuccess: () => toast.success('Approved'),
});
```

### Active route highlighting

Use `usePage().url` and a small helper:

```ts
// hooks/use-active-route.ts
import { usePage } from '@inertiajs/react';

export function useActiveRoute() {
  const { url } = usePage();
  return (...names: string[]) => names.some((n) => route().current(n));
}
```

---

## 11. Performance

### Render thinking

React re-renders a component when its props or state change. Most UI is fast. Profile before optimizing — premature memoization is worse than no memoization.

### When to memoize

- Pass a callback to a memoized child (`React.memo`-wrapped) — wrap with `useCallback`.
- Compute a derived value on a large dataset (1000+ items) — wrap with `useMemo`.
- Hand a component into a context or store — wrap to keep referential stability.

For everyday components rendering ~50 rows, leave them un-memoized. The browser handles it fine.

### `React.memo`

Wrap leaf components rendered in long lists:

```tsx
export const JournalEntryRow = memo(function JournalEntryRow({ entry }: Props) {
  // ...
});
```

Don't memo a component that always receives new prop references — the memoization does nothing.

### Code splitting

Inertia handles per-page code splitting automatically (Vite). For very large components used conditionally on a page (a heavy chart, a code editor):

```tsx
const ChartPanel = lazy(() => import('@/components/dashboard/chart-panel'));

<Suspense fallback={<Skeleton className="h-64 w-full" />}>
  <ChartPanel />
</Suspense>
```

### Large lists

- 100 rows: render normally.
- 1,000 rows: paginate. Always.
- 10,000+ rows that genuinely need to be in one view (rare in payroll): virtualize with `@tanstack/react-virtual`.

### Image optimization

- Use `loading="lazy"` on every `<img>` below the fold.
- Pre-size with explicit `width` and `height` to prevent layout shift.
- For employee avatars and the like: serve appropriately-sized images from the backend; don't ship 4K assets.

---

## 12. Error Handling

### Error boundaries

Wrap the app shell with an error boundary to catch render errors:

```tsx
// components/error-boundary.tsx
import { Component, type ReactNode } from 'react';

interface State { error: Error | null; }

export class ErrorBoundary extends Component<{ children: ReactNode }, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error) {
    return { error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // Log to backend / Sentry
    console.error(error, info);
  }

  render() {
    if (this.state.error) {
      return <ErrorState error={this.state.error} onReset={() => this.setState({ error: null })} />;
    }
    return this.props.children;
  }
}
```

Mount once at the layout level. Per-feature boundaries are fine for risky widgets (charts, third-party embeds).

### Async errors

Error boundaries don't catch async errors. Handle them in the call site:

```tsx
const { mutate } = useMutation({
  mutationFn: postEntry,
  onError: (err) => {
    toast.error(parseError(err));
  },
});
```

For Inertia requests, `onError` is part of the visit options:

```tsx
router.post(route('journal-entries.post', id), {}, {
  onError: (errors) => {
    toast.error(Object.values(errors)[0] ?? 'Could not post entry.');
  },
});
```

### User-facing messages

- Validation errors: rendered next to the field (no toast).
- Authorization errors (403): toast + redirect to a safe page.
- Server errors (500): toast with a generic message ("Something went wrong. Please try again.") + log to console for the developer.
- Network errors: toast with retry suggestion.

NEVER show stack traces or raw error JSON to the user.

---

## 13. Testing

### Vitest + Testing Library

```bash
npm run test          # vitest in watch mode
npm run test:ci       # vitest run --coverage
```

### What to test

- **Domain components** with logic (Money, JournalEntryForm validation handling, table filters).
- **Custom hooks** with non-trivial logic (debouncing, URL sync, derived state).
- **Critical user flows** at the page level (creating an entry, approving a payroll run) — render the page with mocked Inertia props.

### What NOT to test

- shadcn primitives (already tested by shadcn / Radix).
- Layout-only components.
- Pure formatting helpers covered by their library (date-fns, Intl).
- Implementation details — test behavior, not internals.

### Patterns

```tsx
// components/__tests__/money.test.tsx
import { render, screen } from '@testing-library/react';
import { Money } from '@/components/money';

describe('Money', () => {
  it('formats positive amounts in PHP', () => {
    render(<Money amount={1245300.5} />);
    expect(screen.getByText(/PHP 1,245,300.50/)).toBeInTheDocument();
  });

  it('renders negative amounts with a minus sign and destructive color when signed', () => {
    const { container } = render(<Money amount={-500} signed />);
    expect(container.textContent).toMatch(/−PHP 500\.00/);
    expect(container.firstChild).toHaveClass('text-destructive');
  });
});
```

### Mocking Inertia

Provide a minimal page-props mock for page-level tests. Use a test utility:

```tsx
// test/render-with-inertia.tsx
import { render } from '@testing-library/react';
import type { ReactElement } from 'react';

export function renderWithInertia(ui: ReactElement, props: Partial<PageProps> = {}) {
  // Stub usePage().props with sensible defaults merged with props
  // ...
  return render(ui);
}
```

Don't try to test the full Inertia round-trip in unit tests — that's what the backend Pest feature tests are for.

### User events

Use `@testing-library/user-event` over `fireEvent`:

```tsx
import userEvent from '@testing-library/user-event';

const user = userEvent.setup();
await user.type(screen.getByLabelText('Reference'), 'JE-2026-001');
await user.click(screen.getByRole('button', { name: /create/i }));
```

Query by role and accessible name. Avoid `getByTestId` except as a last resort — if you need a test ID, the markup probably needs a role or label.

---

## 14. Imports & Modules

### Path alias

`@/` maps to `resources/js/`. Use it everywhere — never `../../../components`.

```ts
// GOOD
import { Button } from '@/components/ui/button';
import { Money } from '@/components/money';

// BAD
import { Button } from '../../components/ui/button';
```

### Import order

Enforced by Prettier + `@trivago/prettier-plugin-sort-imports`:

1. Side-effect imports (`import 'normalize.css'`)
2. Node / external (`react`, `lucide-react`, `@inertiajs/react`)
3. Internal absolute (`@/components/...`, `@/lib/...`, `@/types/...`)
4. Relative (`./...`)
5. Type-only imports last within each group

```ts
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import type { JournalEntry } from '@/types/journal-entry';
```

### Barrel files (`index.ts`) — avoid

Don't add `index.ts` files that re-export. They:
- Defeat tree shaking for some bundler configurations.
- Make refactors harder (find/replace doesn't work).
- Hide what's actually exported.

Exception: `components/ui/` follows the shadcn convention if the CLI generates one — leave it.

### Default exports — page components only

- **Default export:** Inertia pages (required by Inertia for the page resolver).
- **Named exports:** everything else — components, hooks, utilities, types.

```tsx
// pages/JournalEntries/Index.tsx
export default function Index() { ... }   // required default

// components/money.tsx
export function Money() { ... }            // named
```

---

## 15. Code Quality

### Prettier — enforced

`.prettierrc`:

```jsonc
{
  "semi": true,
  "singleQuote": true,
  "tabWidth": 2,
  "trailingComma": "all",
  "printWidth": 100,
  "plugins": ["prettier-plugin-tailwindcss", "@trivago/prettier-plugin-sort-imports"]
}
```

`prettier-plugin-tailwindcss` orders class names canonically. CI fails on unformatted diffs.

### ESLint — enforced

`eslint.config.js` extends:
- `@typescript-eslint/recommended-type-checked`
- `eslint-plugin-react/recommended`
- `eslint-plugin-react-hooks/recommended`
- `eslint-plugin-jsx-a11y/recommended`

Custom rules:
- `no-restricted-imports` blocks `axios` direct imports (must use `@/lib/api`).
- `no-restricted-syntax` blocks `console.log` in committed code (`console.warn` and `console.error` allowed).

### Naming hygiene

- Avoid abbreviations except domain-standard ones (`amt`, `qty` are NOT okay; `id`, `url`, `api` are).
- Don't prefix component variables with `_`.
- Don't use Hungarian notation (`strName`, `bIsValid`).

### Comments

- Code first; comments only when the code can't be made self-explanatory.
- Comment the **why**, not the **what**.
- TODO/FIXME comments include a name and date: `// TODO(jason, 2026-05-02): switch to Combobox once option count exceeds 8`.
- JSDoc on exported utilities and complex types — TypeScript provides most of the value, but a short summary helps editors.

```ts
/**
 * Convert decimal pesos (string) to integer centavos (cents).
 * Uses bcmath-equivalent string math to avoid float drift.
 */
export function pesosToCents(pesos: string): number { ... }
```

### Forbidden

- `console.log` in committed code
- `// @ts-ignore` / `// @ts-nocheck` / `// eslint-disable-next-line` without an explanatory comment
- Commented-out code (delete it; git remembers)
- Unused imports, unused variables (ESLint catches both)
- `String.prototype.replaceAll` without a polyfill check (we target modern browsers, so this one is fine — but check before reaching for new APIs)
- Default exports on non-page components
- Direct `axios` import outside `@/lib/api`
- `window.location` for navigation
- `setTimeout(fn, 0)` to "defer" — refactor the cause

---

## Pre-merge Gate

Every PR runs:

```bash
npm run lint           # eslint
npm run format:check   # prettier --check
npm run typecheck      # tsc --noEmit
npm run test:ci        # vitest --coverage
```

CI enforces all four. Local pre-commit hook recommended (`lint-staged` + `husky`).

---

*This file is the contract for frontend implementation. PRs introducing frontend changes must conform — or update this file together with the change.*
