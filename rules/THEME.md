# THEME.md — Payroll & Accounting System

> Global theme specification for the standalone Payroll & Accounting module attached to the existing LMS database. This document is the single source of truth for visual design across all pages and components. Built on Laravel Starter Kit + Inertia + React + shadcn/ui, styled with Tailwind CSS v4.

---

## 1. Design Philosophy

The interface is inspired by the **Claude / Anthropic aesthetic**: a warm, paper-like cream background, a confident book-cloth orange accent, restrained typography, and generous whitespace. The system reads like a well-printed accounting ledger rather than a dashboard.

### Core principles

1. **Warm, not sterile.** Backgrounds are cream/ivory, not pure white. Dark mode is warm charcoal, not pure black.
2. **One accent color, used sparingly.** Book Cloth orange is reserved for primary actions, focus states, key figures, and brand moments. It must never wallpaper the screen.
3. **Numbers are first-class citizens.** Financial figures use tabular numerals, right-aligned, with semantic color only when the sign carries meaning.
4. **Density with breathing room.** Accounting users scan rows quickly — rows are tight, but typography stays readable and gutters generous.
5. **No decorative emoji in the UI or code.** Iconography is `lucide-react` only.
6. **Calm by default, loud when it matters.** Destructive and warning states are reserved for actual destructive and warning moments.

---

## 2. Color System

### 2.1 Brand palette (raw)

| Token | Hex | Role |
|---|---|---|
| Book Cloth | `#CC785C` | Primary accent, brand orange |
| Book Cloth Hover | `#B8694F` | Pressed / hover for primary |
| Kraft | `#D4A27F` | Soft accent, illustrations |
| Manilla | `#EBDBBC` | Subtle highlight, marker bg |
| Cream | `#FAF9F5` | Light app background |
| Cream Raised | `#FFFFFF` | Cards on cream |
| Cloud Light | `#F0F0EB` | Muted surfaces (light) |
| Cloud Border | `#E5E4DF` | Hairline borders (light) |
| Slate 500 | `#91918D` | Muted text |
| Slate 700 | `#40403E` | Body text on cream |
| Faded Black | `#1F1E1D` | Headings, dark bg |
| Charcoal | `#262624` | Cards on dark bg |
| Forest | `#3F8F5F` | Positive / credit |
| Brick | `#B5483B` | Negative / debit / destructive |
| Amber | `#C08A2E` | Pending / warning |
| Indigo | `#4A6FA5` | Informational |

### 2.2 CSS variables (Tailwind v4 / shadcn)

These map directly to shadcn/ui's design tokens. Declare them in `resources/css/app.css`. The starter kit uses the new `@theme` block — keep the variable names shadcn expects so every primitive (`Button`, `Card`, `Input`, etc.) inherits the theme without changes.

```css
/* resources/css/app.css */
@import "tailwindcss";

@plugin "tailwindcss-animate";

@custom-variant dark (&:is(.dark *));

:root {
  /* Surfaces */
  --background: oklch(0.984 0.006 85);        /* #FAF9F5 cream */
  --foreground: oklch(0.205 0.012 60);        /* #1F1E1D faded black */

  --card: oklch(1 0 0);                        /* white card on cream */
  --card-foreground: oklch(0.205 0.012 60);

  --popover: oklch(1 0 0);
  --popover-foreground: oklch(0.205 0.012 60);

  /* Brand */
  --primary: oklch(0.624 0.124 41);            /* #CC785C book cloth */
  --primary-foreground: oklch(0.99 0.005 85);  /* cream on orange */

  --secondary: oklch(0.945 0.012 80);          /* #F0F0EB cloud light */
  --secondary-foreground: oklch(0.32 0.012 60);

  --muted: oklch(0.945 0.012 80);
  --muted-foreground: oklch(0.555 0.012 60);   /* #91918D slate 500 */

  --accent: oklch(0.92 0.025 75);              /* manilla-tinted */
  --accent-foreground: oklch(0.32 0.012 60);

  /* Semantic */
  --destructive: oklch(0.545 0.165 27);        /* #B5483B brick */
  --destructive-foreground: oklch(0.99 0.005 85);

  --success: oklch(0.585 0.115 145);           /* #3F8F5F forest */
  --success-foreground: oklch(0.99 0.005 85);

  --warning: oklch(0.665 0.135 75);            /* #C08A2E amber */
  --warning-foreground: oklch(0.18 0.012 60);

  --info: oklch(0.555 0.085 255);              /* #4A6FA5 indigo */
  --info-foreground: oklch(0.99 0.005 85);

  /* Lines & rings */
  --border: oklch(0.895 0.012 80);             /* #E5E4DF */
  --input: oklch(0.895 0.012 80);
  --ring: oklch(0.624 0.124 41);               /* primary */

  /* Charts (for Recharts in dashboards) */
  --chart-1: oklch(0.624 0.124 41);            /* book cloth */
  --chart-2: oklch(0.585 0.115 145);           /* forest */
  --chart-3: oklch(0.555 0.085 255);           /* indigo */
  --chart-4: oklch(0.665 0.135 75);            /* amber */
  --chart-5: oklch(0.62 0.085 320);            /* dusty plum */

  /* Sidebar (Inertia AppShell) */
  --sidebar: oklch(0.965 0.008 80);
  --sidebar-foreground: oklch(0.32 0.012 60);
  --sidebar-primary: oklch(0.624 0.124 41);
  --sidebar-primary-foreground: oklch(0.99 0.005 85);
  --sidebar-accent: oklch(0.92 0.025 75);
  --sidebar-accent-foreground: oklch(0.32 0.012 60);
  --sidebar-border: oklch(0.895 0.012 80);
  --sidebar-ring: oklch(0.624 0.124 41);

  --radius: 0.625rem;
}

.dark {
  --background: oklch(0.175 0.008 60);         /* #1F1E1D faded black */
  --foreground: oklch(0.945 0.008 85);

  --card: oklch(0.215 0.008 60);               /* #262624 charcoal */
  --card-foreground: oklch(0.945 0.008 85);

  --popover: oklch(0.215 0.008 60);
  --popover-foreground: oklch(0.945 0.008 85);

  --primary: oklch(0.705 0.115 41);            /* lighter orange for dark */
  --primary-foreground: oklch(0.175 0.008 60);

  --secondary: oklch(0.275 0.008 60);
  --secondary-foreground: oklch(0.92 0.008 85);

  --muted: oklch(0.275 0.008 60);
  --muted-foreground: oklch(0.65 0.012 75);

  --accent: oklch(0.32 0.018 60);
  --accent-foreground: oklch(0.92 0.008 85);

  --destructive: oklch(0.605 0.165 27);
  --destructive-foreground: oklch(0.97 0.005 85);

  --success: oklch(0.645 0.115 145);
  --success-foreground: oklch(0.175 0.008 60);

  --warning: oklch(0.725 0.135 75);
  --warning-foreground: oklch(0.175 0.008 60);

  --info: oklch(0.625 0.085 255);
  --info-foreground: oklch(0.97 0.005 85);

  --border: oklch(0.32 0.012 60);
  --input: oklch(0.32 0.012 60);
  --ring: oklch(0.705 0.115 41);

  --chart-1: oklch(0.705 0.115 41);
  --chart-2: oklch(0.645 0.115 145);
  --chart-3: oklch(0.625 0.085 255);
  --chart-4: oklch(0.725 0.135 75);
  --chart-5: oklch(0.68 0.085 320);

  --sidebar: oklch(0.205 0.008 60);
  --sidebar-foreground: oklch(0.92 0.008 85);
  --sidebar-primary: oklch(0.705 0.115 41);
  --sidebar-primary-foreground: oklch(0.175 0.008 60);
  --sidebar-accent: oklch(0.275 0.008 60);
  --sidebar-accent-foreground: oklch(0.92 0.008 85);
  --sidebar-border: oklch(0.275 0.008 60);
  --sidebar-ring: oklch(0.705 0.115 41);
}

@theme inline {
  --color-background: var(--background);
  --color-foreground: var(--foreground);
  --color-card: var(--card);
  --color-card-foreground: var(--card-foreground);
  --color-popover: var(--popover);
  --color-popover-foreground: var(--popover-foreground);
  --color-primary: var(--primary);
  --color-primary-foreground: var(--primary-foreground);
  --color-secondary: var(--secondary);
  --color-secondary-foreground: var(--secondary-foreground);
  --color-muted: var(--muted);
  --color-muted-foreground: var(--muted-foreground);
  --color-accent: var(--accent);
  --color-accent-foreground: var(--accent-foreground);
  --color-destructive: var(--destructive);
  --color-destructive-foreground: var(--destructive-foreground);
  --color-success: var(--success);
  --color-success-foreground: var(--success-foreground);
  --color-warning: var(--warning);
  --color-warning-foreground: var(--warning-foreground);
  --color-info: var(--info);
  --color-info-foreground: var(--info-foreground);
  --color-border: var(--border);
  --color-input: var(--input);
  --color-ring: var(--ring);

  --color-chart-1: var(--chart-1);
  --color-chart-2: var(--chart-2);
  --color-chart-3: var(--chart-3);
  --color-chart-4: var(--chart-4);
  --color-chart-5: var(--chart-5);

  --color-sidebar: var(--sidebar);
  --color-sidebar-foreground: var(--sidebar-foreground);
  --color-sidebar-primary: var(--sidebar-primary);
  --color-sidebar-primary-foreground: var(--sidebar-primary-foreground);
  --color-sidebar-accent: var(--sidebar-accent);
  --color-sidebar-accent-foreground: var(--sidebar-accent-foreground);
  --color-sidebar-border: var(--sidebar-border);
  --color-sidebar-ring: var(--sidebar-ring);

  --radius-sm: calc(var(--radius) - 4px);
  --radius-md: calc(var(--radius) - 2px);
  --radius-lg: var(--radius);
  --radius-xl: calc(var(--radius) + 4px);

  --font-sans: "Inter", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  --font-serif: "Source Serif 4", "Source Serif Pro", "Iowan Old Style", Georgia, serif;
  --font-mono: "JetBrains Mono", "Fira Code", ui-monospace, SFMono-Regular, Menlo, monospace;
}
```

### 2.3 Color usage rules

- **Primary** is for: the main CTA on a page, the active sidebar item, focus rings, the brand mark, and key headline numerals on dashboard cards. Never as a section background.
- **Secondary** is for: secondary buttons, table header backgrounds, inactive tab fills.
- **Muted** is for: empty-state surfaces, disabled inputs, helper text backgrounds.
- **Success / Destructive** are reserved for **money signs and state**: a credit posting, a payroll approval, a void/delete action. They are never used as decoration.
- **Warning / Info** are for banners and badges only.

---

## 3. Typography

### 3.1 Font stack

| Family | Use |
|---|---|
| **Inter** (sans) | All UI: navigation, buttons, body, table cells, forms |
| **Source Serif 4** (serif) | Page titles, ledger headings, formal report headers, payslip masthead |
| **JetBrains Mono** (mono) | Account codes, reference numbers, IDs, JSON previews |

Load via `<link>` in `resources/views/app.blade.php`:

```html
<link rel="preconnect" href="https://fonts.googleapis.com" />
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
<link
  rel="stylesheet"
  href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,400;8..60,500;8..60,600&family=JetBrains+Mono:wght@400;500;600&display=swap"
/>
```

### 3.2 Type scale

Use Tailwind utilities directly. The scale below is the canonical mapping for this project.

| Role | Class | Size / Leading | Weight | Family |
|---|---|---|---|---|
| Display (rare, dashboard hero) | `text-4xl tracking-tight` | 36 / 40 | 600 | serif |
| Page title | `text-2xl tracking-tight` | 24 / 32 | 600 | serif |
| Section title | `text-lg` | 18 / 28 | 600 | sans |
| Card title | `text-base` | 16 / 24 | 600 | sans |
| Body | `text-sm` | 14 / 20 | 400 | sans |
| Body strong | `text-sm` | 14 / 20 | 500 | sans |
| Label | `text-sm` | 14 / 20 | 500 | sans |
| Helper / caption | `text-xs` | 12 / 16 | 400 | sans |
| Table cell | `text-sm` | 14 / 20 | 400 | sans |
| Numeric (figures) | `text-sm font-medium tabular-nums` | 14 / 20 | 500 | sans |
| Code / IDs | `text-xs font-mono` | 12 / 16 | 500 | mono |

### 3.3 Numerals

All financial figures use `tabular-nums` so columns align. Wrap any monetary cell or stat number in:

```tsx
<span className="font-mono tabular-nums">{formatPHP(amount)}</span>
```

Use `font-mono` only for codes and IDs, **not** for currency — currency stays in Inter with `tabular-nums`. The mono treatment is reserved for account codes (`1100-001`), invoice references, and journal IDs.

---

## 4. Spacing, Radius, Elevation

### 4.1 Spacing scale

Use Tailwind's default 4px-base scale. Common page rhythms for this project:

- Section vertical gap: `space-y-6` (24px)
- Card inner padding: `p-6` (24px) for primary cards, `p-4` (16px) for compact
- Form field gap: `space-y-2` (8px) inside a field, `space-y-4` (16px) between fields
- Table cell padding: `px-4 py-2.5` for list rows, `px-4 py-3` for header

### 4.2 Radius

`--radius: 0.625rem` (10px). Maps to:

- `rounded-sm` → 6px (badges, chips)
- `rounded-md` → 8px (inputs, buttons)
- `rounded-lg` → 10px (cards, dialogs)
- `rounded-xl` → 14px (feature panels, hero blocks)
- `rounded-full` → avatars, pill filters

### 4.3 Elevation

Shadows are restrained — the warm palette already creates separation.

| Level | Class | Use |
|---|---|---|
| 0 (flat) | none | Default cards, table rows |
| 1 | `shadow-sm` | Hovered table rows, raised cards in dashboards |
| 2 | `shadow-md` | Dropdown menus, popovers |
| 3 | `shadow-lg` | Dialogs, drawers |

Never combine shadows with strong borders. Either a hairline border *or* a shadow.

---

## 5. shadcn/ui Component Tokens

Every shadcn primitive picks up the theme automatically through the CSS variables above. The conventions below standardize their *usage* across the project.

### 5.1 Button

```tsx
import { Button } from "@/components/ui/button";

<Button>Save payroll run</Button>                          // primary
<Button variant="secondary">Export</Button>                // secondary action
<Button variant="outline">Cancel</Button>                  // dismiss
<Button variant="ghost">Edit</Button>                      // inline / table actions
<Button variant="destructive">Void entry</Button>          // confirms destructive
<Button variant="link">View ledger</Button>                // text-only nav
```

Rules:
- **One primary button per view.** A page may have multiple secondary buttons but only one primary.
- Destructive buttons are only inside confirmation dialogs, never on a primary toolbar.
- Loading state: pass `disabled` plus a `lucide-react` `Loader2` with `className="animate-spin"`.

### 5.2 Card

The default surface for every grouped piece of content.

```tsx
<Card>
  <CardHeader>
    <CardTitle>Pay Period — November 2026</CardTitle>
    <CardDescription>15 employees · PHP 1,245,300.00 net</CardDescription>
  </CardHeader>
  <CardContent>{/* … */}</CardContent>
  <CardFooter className="justify-end gap-2">{/* actions */}</CardFooter>
</Card>
```

### 5.3 Input, Textarea, Select

All inputs use `h-9` by default. Forms use the standard label-above pattern from the starter kit. Errors render below the field in `text-sm text-destructive`.

### 5.4 Table (data tables)

Tables are the most-used component in payroll & accounting. Conventions:

- Header row: `bg-muted/40 text-muted-foreground text-xs uppercase tracking-wide`
- Row hover: `hover:bg-muted/50`
- Selected row: `data-[state=selected]:bg-accent`
- Numeric columns: `text-right tabular-nums`
- Action column: `text-right`, contains only ghost icon-buttons
- Empty state: span all columns, `text-center text-muted-foreground py-12`

### 5.5 Badge (status pills)

Map domain states to fixed variants:

| State | Variant | Color |
|---|---|---|
| Draft | `secondary` | muted |
| Pending approval | `warning` | amber |
| Approved | `default` | primary |
| Posted / Paid | `success` | forest |
| Voided / Failed | `destructive` | brick |
| Archived | `outline` | hairline |

Custom badge classes (extend `badgeVariants`):

```tsx
// components/ui/badge.tsx — add to cva variants
success: "border-transparent bg-success/15 text-success",
warning: "border-transparent bg-warning/15 text-warning",
info:    "border-transparent bg-info/15 text-info",
```

### 5.6 Dialog & Sheet

- **Dialog** for confirmations, simple forms (≤ 5 fields).
- **Sheet** (right-side, `w-[480px]` or `w-[640px]`) for record detail and create/edit on list pages — preferred over full-page navigation for accounting workflows.
- **AlertDialog** is only for irreversible actions (void, delete, post-to-GL).

### 5.7 Sonner (toasts)

Use `sonner` (the shadcn-recommended toast). Map flash messages to variants:

```tsx
// in resources/js/components/flash-listener.tsx
toast.success(flash.success);
toast.error(flash.error);
toast.warning(flash.warning);
toast.info(flash.info);
```

---

## 6. Domain-Specific Patterns

### 6.1 Money component

A single component owns currency rendering. Place at `resources/js/components/money.tsx`.

```tsx
import { cn } from "@/lib/utils";

interface MoneyProps {
  amount: number | string;          // amount in pesos (already converted from cents)
  currency?: string;                // default: PHP
  signed?: boolean;                 // colors negative red, positive green
  showSymbol?: boolean;
  className?: string;
}

export function Money({
  amount,
  currency = "PHP",
  signed = false,
  showSymbol = true,
  className,
}: MoneyProps) {
  const value = typeof amount === "string" ? Number(amount) : amount;
  const formatted = new Intl.NumberFormat("en-PH", {
    style: showSymbol ? "currency" : "decimal",
    currency,
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Math.abs(value));

  const isNegative = value < 0;
  const isPositive = value > 0;

  return (
    <span
      className={cn(
        "tabular-nums",
        signed && isNegative && "text-destructive",
        signed && isPositive && "text-success",
        className,
      )}
    >
      {isNegative ? "−" : ""}
      {formatted}
    </span>
  );
}
```

Rules:
- Default rendering is **monochrome** (foreground color). Color is opt-in via `signed`.
- Use `signed` on journal entries, P&L lines, and net change figures. **Do not** use `signed` on payroll gross/net totals — those are always positive.
- Always right-align in tables: wrap the `<TableCell>` with `className="text-right"`.

### 6.2 Stat card (KPI)

Used on dashboards for headline figures.

```tsx
<Card>
  <CardHeader className="pb-2">
    <CardDescription>Net payroll · this period</CardDescription>
    <CardTitle className="text-3xl font-serif tabular-nums">
      <Money amount={1245300} />
    </CardTitle>
  </CardHeader>
  <CardContent className="text-xs text-muted-foreground">
    <span className="text-success">+4.2%</span> vs. last period
  </CardContent>
</Card>
```

### 6.3 Ledger / journal table

Two-column money layout: **Debit** and **Credit**, each right-aligned, only one populated per row.

```tsx
<TableHead className="text-right">Debit</TableHead>
<TableHead className="text-right">Credit</TableHead>
```

Account codes use `font-mono` to align the hierarchy visually:

```tsx
<TableCell className="font-mono text-xs">{entry.account_code}</TableCell>
```

### 6.4 Payslip header

Payslips are the only place where the serif type goes large. Use:

```tsx
<header className="border-b pb-6">
  <p className="text-xs font-mono uppercase tracking-widest text-muted-foreground">
    Payslip · {payPeriod}
  </p>
  <h1 className="mt-2 font-serif text-3xl tracking-tight">{employee.name}</h1>
  <p className="text-sm text-muted-foreground">{employee.position}</p>
</header>
```

### 6.5 Print styles

Payslips and reports must print cleanly. Add to `app.css`:

```css
@media print {
  :root {
    --background: #ffffff;
    --foreground: #000000;
    --card: #ffffff;
    --border: #d1d1cc;
  }

  .no-print { display: none !important; }
  body { font-size: 11pt; }
  .page-break { break-after: page; }
}
```

Wrap toolbars, navigation, and action buttons in `className="no-print"` on report pages.

---

## 7. Iconography

- **Library:** `lucide-react` exclusively. Do not introduce additional icon packs.
- **No emoji** in UI labels, button text, table content, toasts, or code comments.
- Default size: `h-4 w-4` for inline buttons and table actions, `h-5 w-5` for sidebar nav, `h-6 w-6` for empty states.
- Icons inside buttons get `mr-2` when paired with text.

Common mapping:

| Concept | Icon |
|---|---|
| Create | `Plus` |
| Edit | `Pencil` |
| Delete / void | `Trash2` |
| Approve / post | `Check` |
| Reject | `X` |
| Export | `Download` |
| Import | `Upload` |
| Filter | `SlidersHorizontal` |
| Search | `Search` |
| Settings | `Settings` |
| Employee | `User` / `Users` |
| Payroll | `Wallet` |
| Ledger | `BookOpen` |
| Report | `FileText` |
| Period | `Calendar` |

---

## 8. Layout & Application Shell

The starter kit ships with an `AppLayout`. Standard page anatomy:

```
┌────────────────────────────────────────────────┐
│ Sidebar (var(--sidebar))                       │
│ ┌────────────────────────────────────────────┐ │
│ │ TopBar (sticky, h-14, border-b)            │ │
│ │ ┌────────────────────────────────────────┐ │ │
│ │ │ Page container                         │ │ │
│ │ │   max-w-screen-2xl mx-auto px-6 py-8   │ │ │
│ │ │                                        │ │ │
│ │ │   <PageHeader />                       │ │ │
│ │ │   <Card>...</Card>                     │ │ │
│ │ │                                        │ │ │
│ │ └────────────────────────────────────────┘ │ │
│ └────────────────────────────────────────────┘ │
└────────────────────────────────────────────────┘
```

### 8.1 PageHeader component

Standard component at `resources/js/components/page-header.tsx`:

```tsx
interface PageHeaderProps {
  eyebrow?: string;          // small uppercase mono label above title
  title: string;
  description?: string;
  actions?: React.ReactNode; // buttons aligned right
}
```

Renders:

```tsx
<div className="mb-6 flex items-end justify-between gap-4 border-b pb-4">
  <div>
    {eyebrow && (
      <p className="text-xs font-mono uppercase tracking-widest text-muted-foreground">
        {eyebrow}
      </p>
    )}
    <h1 className="mt-1 font-serif text-2xl tracking-tight">{title}</h1>
    {description && (
      <p className="mt-1 text-sm text-muted-foreground">{description}</p>
    )}
  </div>
  {actions && <div className="flex gap-2">{actions}</div>}
</div>
```

Every Inertia page (`Index.tsx`, `Show.tsx`, `Create.tsx`, `Edit.tsx`) opens with this component.

### 8.2 Container widths

| Page type | Max width |
|---|---|
| List / table | `max-w-screen-2xl` |
| Form (single column) | `max-w-2xl` |
| Detail / show | `max-w-5xl` |
| Report / payslip | `max-w-4xl` (also print target) |

---

## 9. Motion

Animations are subtle. Use `tailwindcss-animate` (already shipped with shadcn).

- **Dialogs / sheets / popovers:** default shadcn fade + scale, 150ms.
- **Skeletons:** `animate-pulse` on `bg-muted`.
- **Loading spinners:** lucide `Loader2` with `animate-spin`.
- **Page transitions:** none. Inertia's default snap is preferred — no custom route animations.
- **Number ticker / count-up:** avoid. Render the final figure immediately.

---

## 10. Dark Mode

Dark mode is supported and uses the `.dark` class on `<html>`. The starter kit's `useAppearance` hook handles toggling — do not add a separate provider.

In dark mode:
- Cream → faded-black background.
- Book cloth orange lifts slightly (lighter, more saturated) so it stays legible on dark.
- Borders shift from `#E5E4DF` to `oklch(0.32 0.012 60)` — still hairline, never harsh.
- Success/destructive/warning lift in lightness for contrast.

Always test screens in both modes. Tables and money components are the most likely to break — verify column alignment and semantic colors in dark.

---

## 11. Accessibility Floor

Non-negotiable, enforced in code review:

1. **Contrast:** body text on background must hit WCAG AA (4.5:1). The palette above is verified — do not introduce new combinations without checking.
2. **Focus rings:** never remove. The `--ring` token (book cloth) is visible against every surface in the system.
3. **Semantic color is never the only signal.** A red number must also have a sign or label. A success badge must have text, not just color.
4. **Touch targets:** minimum `h-9` (36px) for all interactive elements. Icon-only buttons use `size="icon"` which yields `h-9 w-9`.
5. **Keyboard:** every interactive component (`Dialog`, `Sheet`, `Select`, `Combobox`) ships with shadcn's keyboard handling — do not override.
6. **Tables** use proper `<TableHeader>` / `<TableHead>` semantics. Sortable columns have `aria-sort`.

---

## 12. File Layout

Where theme assets live in the codebase:

```
resources/
├── css/
│   └── app.css                       # all CSS variables, @theme block, print styles
├── js/
│   ├── components/
│   │   ├── ui/                       # shadcn primitives (do not edit unless extending variants)
│   │   ├── money.tsx                 # currency renderer (Section 6.1)
│   │   ├── page-header.tsx           # standard page heading (Section 8.1)
│   │   ├── stat-card.tsx             # KPI card (Section 6.2)
│   │   ├── status-badge.tsx          # domain-state badge (Section 5.5)
│   │   └── flash-listener.tsx        # Inertia flash → sonner bridge
│   ├── layouts/
│   │   └── app-layout.tsx            # shell with sidebar + topbar
│   └── lib/
│       ├── utils.ts                  # cn() helper
│       └── format.ts                 # formatPHP, formatDate, formatPercent
└── views/
    └── app.blade.php                 # font links, viewport, theme bootstrap
```

---

## 13. Reuse Checklist

Before introducing **any** new color, font size, radius, or shadow value, confirm:

- [ ] Is this already covered by an existing token in Section 2.2 or the type scale in Section 3.2?
- [ ] If domain-specific (a new state, a new chart series), can it map to an existing `chart-*` or semantic token?
- [ ] Does the new token belong in `app.css` (project-wide) or in a single component (one-off)? Default to the former — one-off styles are a smell.
- [ ] Does it survive dark mode and print?

If a token genuinely needs to be added, add it to `:root` **and** `.dark` and document it back in this file.

---

## 14. Out of Scope (do not use)

- Gradients on backgrounds or buttons (a single subtle gradient on the auth/login hero is the only exception).
- Glassmorphism / backdrop-blur on data surfaces.
- Neon, rainbow, or saturated accent variants beyond Section 2.1.
- Material Design elevation layers (5+ shadow levels).
- CSS-in-JS, styled-components, CSS modules.
- Custom font weights beyond 400/500/600/700.
- Icon packs other than `lucide-react`.
- Emoji as UI affordances.

---

*This file is the contract. Any pull request that introduces visual changes must either conform to it or update it — never both implicitly.*
