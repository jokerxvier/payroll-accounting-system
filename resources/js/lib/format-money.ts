/**
 * Peso figures as plain strings.
 *
 * `<Money>` renders JSX, which covers every figure in a table or a card but
 * not the two places a chart needs one: an axis tick and a tooltip, where
 * recharts wants a string back from a callback. Before this, the only other
 * formatter in the app was a private `formatCurrency` inside
 * `pages/admin/reports/payroll-summary.tsx` — a second copy of the same
 * `Intl` call, which is how two screens end up disagreeing about what a peso
 * looks like.
 *
 * `<Money>` now renders through `formatMoney()`, so there is one definition.
 *
 * Every function here takes **pesos**, not centavos, matching `<Money>`'s
 * `amount` prop. Callers divide at the boundary: figures cross the wire as
 * integer centavos and become pesos exactly once, where they are displayed.
 */

/**
 * The full figure, two decimal places — `₱1,234,567.89`.
 *
 * Always the absolute value; a caller that wants a sign renders it, as
 * `<Money signed>` does with a real minus (U+2212) rather than a hyphen.
 */
export function formatMoney(
    pesos: number,
    options: { showSymbol?: boolean; currency?: string } = {},
): string {
    const { showSymbol = true, currency = 'PHP' } = options;

    return new Intl.NumberFormat('en-PH', {
        style: showSymbol ? 'currency' : 'decimal',
        currency,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Math.abs(pesos));
}

/**
 * Shortened for an axis — `₱1.2M`, `₱450K`, `₱900`.
 *
 * A y-axis labelled ₱1,250,000.00 four times over is mostly punctuation, and
 * the tick column grows wide enough to squeeze the plot. Tooltips still show
 * the full figure, so the exact number is one hover away.
 *
 * One decimal place, and only when it says something: 1.2M earns its decimal,
 * 1.0M does not.
 */
export function formatMoneyCompact(pesos: number): string {
    const magnitude = Math.abs(pesos);
    const sign = pesos < 0 ? '−' : '';

    if (magnitude >= 1_000_000) {
        return `${sign}₱${trimZero(magnitude / 1_000_000)}M`;
    }

    if (magnitude >= 1_000) {
        return `${sign}₱${trimZero(magnitude / 1_000)}K`;
    }

    return `${sign}₱${Math.round(magnitude)}`;
}

function trimZero(value: number): string {
    const oneDecimal = value.toFixed(1);

    return oneDecimal.endsWith('.0') ? oneDecimal.slice(0, -2) : oneDecimal;
}
