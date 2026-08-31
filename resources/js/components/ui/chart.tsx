import type { ReactElement, ReactNode } from 'react';
import { ResponsiveContainer } from 'recharts';
import { cn } from '@/lib/utils';

/**
 * The shell every chart in this app renders inside.
 *
 * Written here rather than pulled from the shadcn registry: its `chart` block
 * wanted to overwrite `card.tsx`, which this project has already customised,
 * and the upstream component carries a payload-shape guessing layer for
 * tooltips that nothing here needs. What is left is the part that matters —
 * a sized, themed container and one tooltip.
 *
 * **Colours come from the design tokens, not from the chart.** `app.css`
 * defines `--chart-1` through `--chart-5` in both themes, so a series asks for
 * `var(--color-chart-2)` and follows the school's palette into dark mode with
 * no work here. Charts must never carry their own hex values (`RULES.md`:
 * semantic tokens only).
 *
 * `ResponsiveContainer` measures its parent, so the parent must have a height.
 * That is what `height` sets, and why it is required rather than optional —
 * a chart in a zero-height box renders nothing at all and looks like a data
 * problem.
 */
export function ChartContainer({
    height = 280,
    className,
    children,
}: {
    /** Pixels. The container fills its parent's width and this height. */
    height?: number;
    className?: string;
    /** A single recharts chart element. */
    children: ReactElement;
}) {
    return (
        <div
            className={cn(
                // Recharts draws its own SVG text; these set the family and
                // size once so every axis and label matches the app rather
                // than falling back to the browser default.
                'w-full [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground',
                '[&_.recharts-cartesian-grid_line]:stroke-border/60',
                '[&_text]:text-xs',
                className,
            )}
            style={{ height }}
        >
            <ResponsiveContainer width="100%" height="100%">
                {children}
            </ResponsiveContainer>
        </div>
    );
}

/** One row of a chart tooltip: a swatch, a label, and a figure. */
export interface ChartTooltipRow {
    label: string;
    value: ReactNode;
    color?: string;
}

/**
 * A tooltip that looks like the rest of the app.
 *
 * Recharts hands its content component a loosely typed payload; the caller
 * maps that to `rows` so the shape stays where the chart's own data shape is
 * known, and this component stays a presentation concern.
 */
export function ChartTooltip({
    title,
    rows,
}: {
    title?: string;
    rows: ChartTooltipRow[];
}) {
    return (
        <div className="rounded-md border bg-popover px-3 py-2 text-popover-foreground shadow-md">
            {title ? (
                <p className="mb-1 text-xs font-medium">{title}</p>
            ) : null}
            <dl className="space-y-0.5">
                {rows.map((row) => (
                    <div
                        key={row.label}
                        className="flex items-center gap-2 text-xs"
                    >
                        {row.color ? (
                            <span
                                aria-hidden
                                className="h-2 w-2 shrink-0 rounded-[2px]"
                                style={{ backgroundColor: row.color }}
                            />
                        ) : null}
                        <dt className="text-muted-foreground">{row.label}</dt>
                        <dd className="ml-auto font-medium tabular-nums">
                            {row.value}
                        </dd>
                    </div>
                ))}
            </dl>
        </div>
    );
}

/**
 * The five series colours, in the order a chart should reach for them.
 *
 * Referenced through the `--color-chart-*` custom properties `app.css`
 * exposes, so light and dark both follow without a second list here.
 */
export const CHART_COLORS = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
] as const;
