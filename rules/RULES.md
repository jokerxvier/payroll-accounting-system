# RULES.md — Payroll & Accounting

Enforceable rules for the Payroll & Accounting system UI. Companion to `THEME.md` (the full design specification). Where `THEME.md` explains the *what* and *why*, this document captures the *do* and *don't*.

---

## Table of Contents

1. [Foundation](#1-foundation)
2. [Colors](#2-colors)
3. [Typography](#3-typography)
4. [Layout](#4-layout)
5. [Financial UI Patterns](#5-financial-ui-patterns)
6. [shadcn/ui Best Practices](#6-shadcnui-best-practices)

---

## 1. Foundation

Non-negotiable rules that apply to ALL UI work.

### Visual Philosophy

- Warm, paper-like aesthetic. Backgrounds are cream (light) or warm charcoal (dark) — NEVER pure white or pure black.
- One accent only: Book Cloth orange (`primary`). Reserved for the single primary CTA, focus rings, active sidebar item, brand mark, and headline KPI numerals. Never as section background.
- Numbers come first. Financial figures are tabular, right-aligned, monochrome by default.
- Calm by default; loud only when it matters (destructive / warning).
- Density with breathing room — rows are tight, but typography stays readable.

### Always

- Use Tailwind utility classes with the design tokens defined in `resources/css/app.css`.
- Use `lucide-react` for ALL icons.
- Pair semantic color with text or icon — color alone is never the only signal.
- Test screens in BOTH light and dark mode before merging.
- Use the `cn()` helper from `@/lib/utils` for conditional class merging.

### Never

- Use emoji in UI labels, button text, table content, toasts, or as visual affordances. (Code comments included.)
- Add icon packs other than `lucide-react`.
- Introduce new colors, font sizes, radii, or shadow values without first checking the existing token set.
- Use inline styles, CSS modules, styled-components, or any CSS-in-JS.
- Use gradients on data surfaces. (The auth/login hero is the single allowed exception.)
- Use glassmorphism / `backdrop-blur` on data surfaces.
- Remove focus rings.
- Use animated count-up / number tickers on financial figures.

### Iconography

- Inline buttons & table actions: `h-4 w-4`
- Sidebar nav: `h-5 w-5`
- Empty states: `h-6 w-6`
- Icon + text in button: `mr-2` between icon and text
- Icon-only buttons: `<Button size="icon">` (yields `h-9 w-9`)

### Accessibility Floor

- WCAG AA contrast minimum (4.5:1 for body text). Tokens in `app.css` are pre-verified — don't introduce off-token combinations.
- Touch targets: minimum `h-9` (36px) for all interactive elements.
- Tables use semantic `<TableHeader>` / `<TableHead>`. Sortable columns get `aria-sort`.
- Keyboard handling comes from shadcn primitives (Radix under the hood) — do not override.
- Every `<Input>`, `<Textarea>`, `<Select>` is paired with a `<Label htmlFor>`.
- Every `Dialog` and `Sheet` has a `<DialogTitle>` / `<SheetTitle>` (use `<VisuallyHidden>` if not visually shown).
- Provide `aria-label` on icon-only buttons.

### Out of Scope (do not introduce)

- Gradients on data surfaces
- Glassmorphism / `backdrop-blur` on data surfaces
- Material Design elevation layers (5+ shadow levels)
- Custom font weights beyond 400 / 500 / 600 / 700
- Icon packs other than `lucide-react`
- Emoji as UI affordances

---

## 2. Colors

Reference: `THEME.md` Section 2.

### Token Usage — Always Semantic, Never Raw Hex

Use the design tokens. Never hardcode hex values in components.

| Use case | Token / class |
|---|---|
| Page background | `bg-background` |
| Card surface | `bg-card text-card-foreground` |
| Body text | `text-foreground` |
| Muted text (helper, captions, placeholders) | `text-muted-foreground` |
| Primary CTA | `bg-primary text-primary-foreground` |
| Secondary action | `bg-secondary text-secondary-foreground` |
| Hairline border | `border-border` |
| Hairline divider | `<Separator />` |
| Focus ring | `ring-ring` (auto on shadcn primitives) |
| Selected / hovered subtle bg | `bg-accent text-accent-foreground` |

### Brand Color: Book Cloth Orange (`primary`)

Reserved for:
- The single primary CTA on a page (one only)
- The active sidebar item
- Focus rings
- The brand mark / logo
- Headline numerals on dashboard KPI cards

NEVER use `bg-primary` as a section background, hero wallpaper, page chrome, or large surface fill.

### Semantic Colors — Money Sign / State ONLY

These are never decorative. Always pair with text or icon.

| Token | Domain meaning |
|---|---|
| `success` (forest) | Credit posting, payroll approved, payment received, positive variance |
| `destructive` (brick) | Debit, void / delete actions, failed transactions, negative variance |
| `warning` (amber) | Pending approval, due-soon, partial payment |
| `info` (indigo) | Informational banners, audit notices |

Soft tint pattern (badges, banners): `bg-{token}/15 text-{token}` — 15% alpha background, full-strength text.
Solid pattern (destructive confirmation buttons inside `AlertDialog` only): `bg-destructive text-destructive-foreground`.

### Charts (Recharts)

Use `var(--chart-1)` through `var(--chart-5)` in chart configs. Order:

1. Brand (book cloth)
2. Success (forest)
3. Info (indigo)
4. Warning (amber)
5. Plum

Never reach for raw hex in chart configs.

### Adding a New Token

If a new token is genuinely required:

1. Add to BOTH `:root` and `.dark` in `resources/css/app.css`
2. Map it in the `@theme inline` block (so Tailwind picks it up)
3. Document it in `THEME.md` Section 2.1 / 2.2
4. Verify dark mode AND print mode

Single-component one-off colors are a smell — fix the root, not the leaf.

### Forbidden

- `style={{ color: '#CC785C' }}` — use `text-primary`
- `bg-orange-500`, `text-red-600`, etc. — use semantic tokens
- New gray scales — use `muted` / `border` / `accent`
- Tailwind opacity > `/15` on semantic backgrounds — keep tints subtle

---

## 3. Typography

Reference: `THEME.md` Section 3.

### Font Stack

| Family | Use | Tailwind |
|---|---|---|
| Inter (sans) | Default UI: nav, buttons, body, table cells, forms | `font-sans` (default) |
| Source Serif 4 | Page titles, ledger headings, payslip mastheads | `font-serif` |
| JetBrains Mono | Account codes, IDs, journal references, debug output | `font-mono` |

### Type Scale — Canonical

| Role | Class |
|---|---|
| Display (rare; dashboard hero only) | `font-serif text-4xl tracking-tight font-semibold` |
| Page title | `font-serif text-2xl tracking-tight font-semibold` |
| Section title | `text-lg font-semibold` |
| Card title | `text-base font-semibold` |
| Body | `text-sm` |
| Body strong | `text-sm font-medium` |
| Form label | `text-sm font-medium` |
| Helper / caption | `text-xs text-muted-foreground` |
| Numeric figure | `text-sm font-medium tabular-nums` |
| Code / IDs | `text-xs font-mono` |
| Eyebrow (uppercase mono label) | `text-xs font-mono uppercase tracking-widest text-muted-foreground` |

Don't introduce sizes outside this scale.

### Font Weights

Allowed: `400`, `500`, `600`, `700`. Nothing else.

### Numerals — Critical

- ALL financial figures use `tabular-nums` (column alignment).
- Currency uses **Inter + `tabular-nums`**, NOT `font-mono`.
- Right-align numeric table columns: `<TableCell className="text-right tabular-nums">`.

### Mono Usage — Restricted

Use `font-mono` ONLY for:
- Account codes (e.g. `1100-001`)
- Invoice / journal references
- Employee IDs
- JSON / debug previews
- The eyebrow label above page titles

Do NOT use mono for currency, dates, names, or labels.

### Page Title Pattern

Every page opens with `<PageHeader />`:

```tsx
<PageHeader
  eyebrow="PAYROLL"
  title="November 2026 Pay Run"
  description="15 employees · review and approve"
  actions={<Button>Approve all</Button>}
/>
```

Internally:
- Eyebrow: `text-xs font-mono uppercase tracking-widest text-muted-foreground`
- Title: `font-serif text-2xl tracking-tight font-semibold`
- Description: `text-sm text-muted-foreground`

### KPI Headline Numerals

KPI / stat card values get serif treatment:

```tsx
<CardTitle className="font-serif text-3xl tabular-nums">
  <Money amount={netPayroll} />
</CardTitle>
```

### Prose / Long-form Text

For any paragraph longer than two lines (e.g., audit notes, policy text):
- Max width `max-w-prose` (~65ch)
- `leading-relaxed`
- `text-foreground` (not muted)

### Forbidden

- `text-3xl`, `text-5xl`, etc. with no purpose — only inside the canonical scale
- `font-light` / `font-thin` / `font-black` — outside allowed weights
- Mono on currency
- Skipping `tabular-nums` on a numeric column

---

## 4. Layout

Reference: `THEME.md` Section 8.

### Page Anatomy — Mandatory Structure

Every Inertia page (`Index.tsx`, `Show.tsx`, `Create.tsx`, `Edit.tsx`) follows this skeleton:

```tsx
import AppLayout from '@/layouts/app-layout';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Plus } from 'lucide-react';

export default function Index() {
  return (
    <AppLayout>
      <PageHeader
        eyebrow="LEDGER"
        title="General Ledger"
        description="All journal entries for the current fiscal year"
        actions={
          <Button>
            <Plus className="mr-2 h-4 w-4" />
            New entry
          </Button>
        }
      />
      <Card>
        <CardHeader>
          <CardTitle>Recent entries</CardTitle>
        </CardHeader>
        <CardContent>{/* table here */}</CardContent>
      </Card>
    </AppLayout>
  );
}
```

NEVER render a page without `AppLayout` and `PageHeader`.

### Container Widths

| Page type | Class |
|---|---|
| List / table | `max-w-screen-2xl` |
| Form (single column) | `max-w-2xl` |
| Detail / show | `max-w-5xl` |
| Report / payslip | `max-w-4xl` |
| Dashboard (multi-card grid) | `max-w-screen-2xl` |

Container wrapper: `mx-auto px-6 py-8`.

### Spacing Rhythm

| Context | Class |
|---|---|
| Section vertical gap | `space-y-6` |
| Card inner padding (default) | `p-6` |
| Card inner padding (compact / dashboard tile) | `p-4` |
| Form field gap (label → input → error) | `space-y-2` |
| Form fields between each other | `space-y-4` |
| Table cell body | `px-4 py-2.5` |
| Table cell header | `px-4 py-3` |
| Page header bottom border | `border-b pb-4 mb-6` |

### Elevation

| Level | Class | Use |
|---|---|---|
| Flat | none | Default cards, table rows |
| Low | `shadow-sm` | Hovered table rows, raised dashboard cards |
| Mid | `shadow-md` | Dropdowns, popovers |
| High | `shadow-lg` | Dialogs, sheets |

NEVER combine a strong border with a shadow on the same element.

### Sidebar Nav

- Active item: `bg-sidebar-accent text-sidebar-accent-foreground`, plus a 2px left indicator in `bg-primary`.
- Inactive: `text-sidebar-foreground hover:bg-sidebar-accent/60`.
- Group labels: `text-xs font-mono uppercase tracking-widest text-muted-foreground px-3 py-2`.
- Nav icons: `h-5 w-5 mr-2`.

### Dashboard Grid

```tsx
<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
  <StatCard ... />
  <StatCard ... />
</div>
```

Mixed layouts:

```tsx
<div className="grid gap-6 lg:grid-cols-3">
  <div className="lg:col-span-2">{/* main content */}</div>
  <div>{/* sidebar widgets */}</div>
</div>
```

### Print Pages

Reports and payslips MUST print clean:

- Wrap toolbars, action buttons, and nav in `className="no-print"`
- Use `className="page-break"` for explicit page breaks between payslips
- Avoid colored backgrounds on report bodies — rely on text + borders
- Test with browser print preview before merging

### Responsive Floor

- Minimum supported width: 360px (mobile)
- Sidebar collapses to drawer on `<lg` (1024px)
- Tables on mobile: horizontal scroll wrapper, never stack rows
- Forms on mobile: single-column, full-width fields

### Forbidden

- Pages without `<AppLayout>` (except auth pages)
- Pages without `<PageHeader>`
- Mixing fixed and fluid containers on the same page
- Custom margins outside the `space-y-*` / `gap-*` rhythm
- Sticky elements other than the top bar and table headers in long lists

---

## 5. Financial UI Patterns

Reference: `THEME.md` Section 6.

### Money Component — Mandatory

ALL currency rendering goes through `<Money>`. Do NOT format with `Intl.NumberFormat` inline.

```tsx
import { Money } from '@/components/money';

<Money amount={1245300.50} />              // PHP 1,245,300.50  (monochrome)
<Money amount={-500} signed />             // −PHP 500.00       (red)
<Money amount={500} signed />              // PHP 500.00        (green)
<Money amount={1000} showSymbol={false} /> // 1,000.00          (no PHP prefix)
```

### When to use `signed`

USE for: journal entries, P&L lines, net change figures, balance changes, variance vs prior period.

DO NOT use for: payroll gross/net totals, invoice totals, account balances (always rendered monochrome and positive).

### Table Money Cells — Always

1. Right-align the column: `<TableCell className="text-right">`
2. Right-align the header: `<TableHead className="text-right">`
3. Wrap value in `<Money>`

```tsx
<TableHead className="text-right">Amount</TableHead>
...
<TableCell className="text-right">
  <Money amount={row.amount} />
</TableCell>
```

### Ledger / Journal Tables

Two-column money layout — `Debit` and `Credit`, both right-aligned, only one populated per row.

```tsx
<TableRow>
  <TableCell className="font-mono text-xs">{entry.account_code}</TableCell>
  <TableCell>{entry.account_name}</TableCell>
  <TableCell>{entry.memo}</TableCell>
  <TableCell className="text-right">
    {entry.debit > 0 ? <Money amount={entry.debit} /> : null}
  </TableCell>
  <TableCell className="text-right">
    {entry.credit > 0 ? <Money amount={entry.credit} /> : null}
  </TableCell>
</TableRow>
```

Account codes always use `font-mono text-xs`.

### Status Badges — Domain States

Map every workflow state to a fixed badge variant. NEVER invent ad-hoc colored badges.

| State | Variant | Visual |
|---|---|---|
| Draft | `secondary` | muted fill |
| Pending approval | `warning` | amber tint |
| Approved | `default` | primary tint |
| Posted / Paid | `success` | forest tint |
| Voided / Failed | `destructive` | brick tint |
| Archived | `outline` | hairline only |

```tsx
<Badge variant="success">Posted</Badge>
<Badge variant="warning">Pending</Badge>
<Badge variant="destructive">Voided</Badge>
```

Always include the text label. NEVER a color-only badge.

### KPI / Stat Card

```tsx
<Card>
  <CardHeader className="pb-2">
    <CardDescription>Net payroll · this period</CardDescription>
    <CardTitle className="font-serif text-3xl tabular-nums">
      <Money amount={netPayroll} />
    </CardTitle>
  </CardHeader>
  <CardContent className="text-xs text-muted-foreground">
    <span className="text-success">+4.2%</span> vs. last period
  </CardContent>
</Card>
```

The delta indicator is the ONLY place inline `text-success` / `text-destructive` is used outside `<Money signed>`.

### Payslip Header

The serif type goes large here — payslips are the most "printed-document" surface in the app.

```tsx
<header className="border-b pb-6">
  <p className="text-xs font-mono uppercase tracking-widest text-muted-foreground">
    Payslip · {payPeriod}
  </p>
  <h1 className="mt-2 font-serif text-3xl tracking-tight">{employee.name}</h1>
  <p className="text-sm text-muted-foreground">{employee.position}</p>
</header>
```

### Currency Input Fields

Use `<Input inputMode="decimal">` — NEVER `<Input type="number">` (browser spinners and locale issues).

```tsx
<Input
  inputMode="decimal"
  pattern="[0-9]*\.?[0-9]*"
  className="text-right tabular-nums"
  value={data.amount}
  onChange={(e) => setData('amount', e.target.value)}
/>
```

### Pay Period / Date Display

Use the `formatDate` helper from `@/lib/format`. Never call `toLocaleString` directly in components.

```tsx
import { formatDate } from '@/lib/format';

<span>{formatDate(entry.posted_at, 'PP')}</span>      // Nov 1, 2026
<span>{formatDate(entry.posted_at, 'PPp')}</span>     // Nov 1, 2026, 9:30 AM
```

### Forbidden

- Template-string currency: `` `PHP ${amount.toFixed(2)}` ``
- `Intl.NumberFormat` calls in components (must be inside `Money` or `formatPHP`)
- Animated count-up tickers on financial values
- `font-mono` on currency
- `signed` Money on totals that are always positive (gross pay, invoice totals)
- Color-only badges (no text label)
- Decoration use of `text-destructive` / `text-success` outside money sign and delta indicators

---

## 6. shadcn/ui Best Practices

shadcn/ui is the ONLY component library for this project. Reference: `THEME.md` Section 5.

### Installation & Updates

- Add components via the CLI: `npx shadcn@latest add <component>`
- NEVER copy-paste shadcn source from the docs by hand — the CLI keeps imports, paths, and dependencies aligned with `components.json`.
- When updating an existing component, re-run the CLI and review the diff. Do not blindly accept overwrites.
- shadcn primitives live at `resources/js/components/ui/`. They are PROJECT-OWNED source — you are allowed to edit them, but only with intent.

#### Initial install for this project

```bash
npx shadcn@latest add button card input label textarea select \
  table dialog sheet alert-dialog dropdown-menu popover \
  badge separator tabs sonner skeleton tooltip command \
  combobox calendar checkbox radio-group switch form
```

### Editing Primitives — When and Why

Acceptable reasons to edit a `components/ui/*.tsx` file:

1. Adding a `cva` variant the project needs (e.g., a `success` button variant, `info` / `warning` badge variants)
2. Wiring a new design token from `app.css`
3. Adjusting a default size to match the project's `h-9` standard

Unacceptable:

- One-off styling for a single screen — wrap or compose instead
- Changing default colors (do this via tokens in `app.css`, not in the component)
- Removing accessibility props (`aria-*`, `data-state`, focus handling)
- Stripping `forwardRef` or `displayName`

### Composition Over Configuration

shadcn primitives are designed to compose. Reach for composition first; props second.

```tsx
// GOOD — composition
<Card>
  <CardHeader>
    <CardTitle>Pay Run</CardTitle>
    <CardDescription>15 employees · PHP 1,245,300.00 net</CardDescription>
  </CardHeader>
  <CardContent>...</CardContent>
  <CardFooter className="justify-end gap-2">
    <Button variant="outline">Cancel</Button>
    <Button>Approve</Button>
  </CardFooter>
</Card>

// BAD — wrapper component with prop explosion
<PayRunCard
  title="Pay Run"
  description="15 employees..."
  onCancel={...}
  onApprove={...}
  showFooter
  variant="approval"
/>
```

Build domain components by composing primitives. Never wrap shadcn primitives just to pass through props.

### `cn()` Utility — Always

For combining classes with conditional logic:

```tsx
import { cn } from '@/lib/utils';

<div
  className={cn(
    'rounded-md border p-4',
    isSelected && 'border-primary bg-primary/5',
    isDisabled && 'opacity-50 pointer-events-none',
    className, // forwarded from props, last so it can override
  )}
/>
```

NEVER manually concatenate classes with `+` or template strings. `cn()` (clsx + tailwind-merge) handles class conflicts correctly — last-wins on conflicting Tailwind utilities.

### `cva` for Variants

When extending a primitive's variants, use `class-variance-authority`:

```tsx
import { cva, type VariantProps } from 'class-variance-authority';

const badgeVariants = cva(
  'inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-semibold',
  {
    variants: {
      variant: {
        default:     'border-transparent bg-primary text-primary-foreground',
        secondary:   'border-transparent bg-secondary text-secondary-foreground',
        destructive: 'border-transparent bg-destructive text-destructive-foreground',
        success:     'border-transparent bg-success/15 text-success',
        warning:     'border-transparent bg-warning/15 text-warning',
        info:        'border-transparent bg-info/15 text-info',
        outline:     'text-foreground',
      },
    },
    defaultVariants: { variant: 'default' },
  },
);

export interface BadgeProps
  extends React.HTMLAttributes<HTMLDivElement>,
    VariantProps<typeof badgeVariants> {}
```

### `asChild` and Slot

Use `asChild` to avoid wrapper divs and to pass styles/behavior to child elements (typically Inertia `<Link>`):

```tsx
import { Link } from '@inertiajs/react';

// GOOD
<Button asChild>
  <Link href={route('payroll.index')}>Go to Payroll</Link>
</Button>

// BAD — produces invalid HTML (button inside anchor)
<Link href={route('payroll.index')}>
  <Button>Go to Payroll</Button>
</Link>

// BAD — extra wrapper that breaks button styling
<Button onClick={() => router.visit(route('payroll.index'))}>
  Go to Payroll
</Button>
```

`asChild` works on Button, DropdownMenuItem, NavigationMenuLink, and any primitive built on Radix Slot.

### Forms — Inertia `useForm`, NOT react-hook-form

This project uses Inertia's `useForm` for all standard CRUD. Do NOT pull in `react-hook-form` or `zod` resolvers — Laravel `FormRequest` handles validation server-side.

```tsx
import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Loader2 } from 'lucide-react';

export function EntryForm() {
  const { data, setData, post, processing, errors, reset } = useForm({
    name: '',
    amount: 0,
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    post(route('entries.store'), {
      onSuccess: () => reset(),
    });
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      <div className="space-y-2">
        <Label htmlFor="name">Name</Label>
        <Input
          id="name"
          value={data.name}
          onChange={(e) => setData('name', e.target.value)}
          aria-invalid={!!errors.name}
        />
        {errors.name && (
          <p className="text-sm text-destructive">{errors.name}</p>
        )}
      </div>

      <Button type="submit" disabled={processing}>
        {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
        Save
      </Button>
    </form>
  );
}
```

Errors come from the server via Inertia. Surface them as `text-sm text-destructive` directly under the field.

#### When to use shadcn `<Form>` (react-hook-form)

The shadcn `Form` primitive wraps react-hook-form. Only reach for it when you have:

- Complex client-side validation that shouldn't roundtrip the server
- Dynamic field arrays with frequent add/remove
- Multi-step wizards with stepwise validation gates

For all standard CRUD pages, use Inertia `useForm`.

### Button Hierarchy — One Primary Per View

| Variant | Use |
|---|---|
| `default` (primary) | THE main action of the page (one only) |
| `secondary` | Co-equal secondary actions (Export, Filter) |
| `outline` | Cancel, dismiss, "back" |
| `ghost` | Inline / table row actions, sidebar nav, tab buttons |
| `destructive` | Inside confirmation dialogs ONLY — never on a toolbar |
| `link` | Text-only navigation inline with prose |

A page can have many secondary buttons. It has one primary. If you find yourself with two primaries, one of them isn't actually primary — make it secondary.

### Loading States

Disabled + spinner pattern:

```tsx
import { Loader2 } from 'lucide-react';

<Button disabled={processing}>
  {processing && <Loader2 className="mr-2 h-4 w-4 animate-spin" />}
  Save
</Button>
```

For initial data loads, use `<Skeleton>`:

```tsx
import { Skeleton } from '@/components/ui/skeleton';

{isLoading ? (
  <div className="space-y-2">
    <Skeleton className="h-4 w-full" />
    <Skeleton className="h-4 w-3/4" />
  </div>
) : (
  <Content />
)}
```

NEVER use a full-screen loading overlay. Skeletons or inline spinners only.

### Dialog vs Sheet vs AlertDialog

Pick the right modal type — they are NOT interchangeable.

| Need | Component | Width / position |
|---|---|---|
| Confirmation, simple form (≤ 5 fields) | `Dialog` | Centered, `max-w-md` to `max-w-lg` |
| Detail view, create/edit on list pages, multi-section forms | `Sheet` | Right side, `w-[480px]` or `w-[640px]` |
| Irreversible action (void, delete, post-to-GL) | `AlertDialog` | Centered, blocks all other interaction |

`Sheet` is preferred over full-page navigation for accounting record edits — keeps the user's context (the list) in view.

`AlertDialog` is the ONLY place destructive buttons appear:

```tsx
<AlertDialog>
  <AlertDialogTrigger asChild>
    <Button variant="ghost" size="icon">
      <Trash2 className="h-4 w-4" />
    </Button>
  </AlertDialogTrigger>
  <AlertDialogContent>
    <AlertDialogHeader>
      <AlertDialogTitle>Void this journal entry?</AlertDialogTitle>
      <AlertDialogDescription>
        This will reverse the posting and create an audit log entry. It cannot be undone.
      </AlertDialogDescription>
    </AlertDialogHeader>
    <AlertDialogFooter>
      <AlertDialogCancel>Cancel</AlertDialogCancel>
      <AlertDialogAction asChild>
        <Button variant="destructive">Void entry</Button>
      </AlertDialogAction>
    </AlertDialogFooter>
  </AlertDialogContent>
</AlertDialog>
```

### Toasts — Sonner

Use `sonner` (the shadcn-recommended toast). Mount one global `<Toaster>` in `app.tsx`.

```tsx
import { toast } from 'sonner';

toast.success('Pay run approved');
toast.error('Failed to post entry');
toast.warning('Period is closing in 2 days');
toast.info('Audit log updated');

// With description
toast.success('Pay run approved', {
  description: 'PHP 1,245,300.00 across 15 employees',
});

// With action
toast('Entry voided', {
  action: {
    label: 'Undo',
    onClick: () => router.post(route('entries.restore', entryId)),
  },
});
```

Bridge Inertia flash messages → sonner via a single `<FlashListener />` mounted in `AppLayout`.

### Tables

Standard table conventions:

```tsx
<Table>
  <TableHeader>
    <TableRow>
      <TableHead>Account</TableHead>
      <TableHead>Memo</TableHead>
      <TableHead className="text-right">Amount</TableHead>
      <TableHead className="w-12 text-right">{/* actions */}</TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {rows.length === 0 ? (
      <TableRow>
        <TableCell colSpan={4} className="text-center text-muted-foreground py-12">
          No entries yet
        </TableCell>
      </TableRow>
    ) : (
      rows.map((row) => (
        <TableRow
          key={row.id}
          className="cursor-pointer"
          onClick={() => router.visit(route('entries.show', row.id))}
        >
          <TableCell className="font-mono text-xs">{row.account_code}</TableCell>
          <TableCell>{row.memo}</TableCell>
          <TableCell className="text-right">
            <Money amount={row.amount} />
          </TableCell>
          <TableCell className="text-right">
            <RowActions entry={row} />
          </TableCell>
        </TableRow>
      ))
    )}
  </TableBody>
</Table>
```

#### Sortable headers

Render the header as a ghost button with a trailing chevron:

```tsx
<Button variant="ghost" size="sm" className="-ml-3 h-8" onClick={() => onSort('amount')}>
  Amount
  <ArrowUpDown className="ml-2 h-3.5 w-3.5" />
</Button>
```

#### Row actions

Use a `<DropdownMenu>` triggered by an icon button:

```tsx
<DropdownMenu>
  <DropdownMenuTrigger asChild>
    <Button variant="ghost" size="icon">
      <MoreHorizontal className="h-4 w-4" />
    </Button>
  </DropdownMenuTrigger>
  <DropdownMenuContent align="end">
    <DropdownMenuItem asChild>
      <Link href={route('entries.edit', row.id)}>Edit</Link>
    </DropdownMenuItem>
    <DropdownMenuSeparator />
    <DropdownMenuItem className="text-destructive" onClick={() => setVoidId(row.id)}>
      Void
    </DropdownMenuItem>
  </DropdownMenuContent>
</DropdownMenu>
```

Stop event propagation if the row itself is clickable:

```tsx
<TableCell onClick={(e) => e.stopPropagation()} className="text-right">
  {/* dropdown here */}
</TableCell>
```

### Select, Combobox, Command

| Need | Component |
|---|---|
| Fixed list, ≤ 8 items, no search | `Select` |
| Fixed list, > 8 items OR needs search | `Combobox` (built from `Popover` + `Command`) |
| Free-text command palette (Cmd+K) | `Command` directly inside a `Dialog` |
| Async-loaded options (employees, accounts) | `Combobox` with debounced fetch |

NEVER mix shadcn `<Select>` with native `<option>` — they are not the same component.

### Tooltip

Wrap one global `<TooltipProvider>` at the app root. Keep tooltips short — they are micro-labels, not documentation.

```tsx
<Tooltip>
  <TooltipTrigger asChild>
    <Button variant="ghost" size="icon">
      <HelpCircle className="h-4 w-4" />
    </Button>
  </TooltipTrigger>
  <TooltipContent>Net of taxes and contributions</TooltipContent>
</Tooltip>
```

Don't wrap whole sections in tooltips. Don't use a tooltip for content that's discoverable elsewhere.

### Accessibility — shadcn-Specific

shadcn primitives ship with Radix accessibility — KEEP IT.

- Don't strip `aria-*` attributes from primitives
- Don't suppress focus rings with `outline-none` (the variant already handles `focus-visible:ring`)
- Always provide `<DialogTitle>` / `<SheetTitle>` (use `<VisuallyHidden>` from `@radix-ui/react-visually-hidden` if not visually shown)
- Always pair `<Label htmlFor>` with every `<Input>`, `<Textarea>`, `<Select>`, `<Checkbox>`, `<RadioGroup>`, `<Switch>`
- `Dialog` and `Sheet` trap focus by default — don't disable it
- Provide `aria-label` on icon-only buttons

```tsx
<Button variant="ghost" size="icon" aria-label="Edit entry">
  <Pencil className="h-4 w-4" />
</Button>
```

### Common Pitfalls

| Mistake | Fix |
|---|---|
| `<Button>` inside `<Link>` | `<Button asChild><Link>` |
| `<form>` inside another `<form>` | Refactor — only one `<form>` per submission |
| Dialog content outside `<DialogContent>` | All children render inside the portal |
| Missing `key` on mapped `<TableRow>` / `<SelectItem>` | Use stable IDs, not array index |
| Mixing `<Select>` with native `<option>` | Use `<SelectItem>` only |
| `Card` inside `Card` | Flatten or use `<Separator>` |
| `<Input type="number">` for currency | `<Input inputMode="decimal">` |
| Removing `displayName` from primitives | Keep it — needed for React DevTools |
| Hardcoding text color in primitive | Use semantic token (`text-foreground`, `text-muted-foreground`) |
| Custom `border-radius` per component | Use `--radius` and `rounded-{sm,md,lg,xl}` |
| Wrapping a Button just to add an icon | Compose: `<Button><Icon/> Label</Button>` |

### Forbidden

- Importing from `@radix-ui/*` directly except for `<VisuallyHidden>` and `<Slot>`
- Adding a non-shadcn UI library (Material UI, Mantine, Chakra, Ant Design, etc.)
- Wrapping shadcn primitives in styled-components / emotion / stitches
- Custom modal implementations (always use `Dialog` / `Sheet` / `AlertDialog`)
- Custom toast implementations (always use sonner)
- Inline `<input type="text">` outside the shadcn `<Input>` primitive
- Removing `forwardRef` from primitives

---

*This file is the contract for UI implementation. Pull requests must conform — or update this file together with the change.*
