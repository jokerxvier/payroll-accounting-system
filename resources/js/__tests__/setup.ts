import '@testing-library/jest-dom/vitest';

// Radix UI primitives (Switch, Select, AlertDialog, etc.) call
// ResizeObserver internally; JSDOM doesn't ship it. A no-op polyfill is
// enough for unit tests since we never assert on observed sizes.
if (typeof globalThis.ResizeObserver === 'undefined') {
    globalThis.ResizeObserver = class ResizeObserver {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
    };
}

// Same story for `hasPointerCapture` / `releasePointerCapture` on Radix
// dismissable layers — JSDOM omits the Pointer Events API.
if (
    typeof Element !== 'undefined' &&
    typeof Element.prototype.hasPointerCapture === 'undefined'
) {
    Element.prototype.hasPointerCapture = (): boolean => false;
    Element.prototype.releasePointerCapture = (): void => {};
    Element.prototype.setPointerCapture = (): void => {};
    // Radix Select uses scrollIntoView on item highlight.
    Element.prototype.scrollIntoView = (): void => {};
}

/*
 * Recharts measures its parent before it draws anything, and jsdom reports
 * every element as 0x0. Without a size, `ResponsiveContainer` renders an empty
 * div and every chart assertion fails for a reason that has nothing to do with
 * the chart.
 *
 * Giving the container a fixed box is enough: the tests assert on the data a
 * chart is handed and the states around it — an empty range, a loading
 * filter — not on pixel geometry, which jsdom could not tell us about anyway.
 */
if (typeof Element !== 'undefined') {
    Object.defineProperty(HTMLElement.prototype, 'offsetWidth', {
        configurable: true,
        value: 800,
    });
    Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
        configurable: true,
        value: 400,
    });
}
