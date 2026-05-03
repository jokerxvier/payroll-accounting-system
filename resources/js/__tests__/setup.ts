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
