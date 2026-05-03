import { act, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const routerPostMock = vi.fn();

vi.mock('@inertiajs/react', () => ({
    router: {
        post: (...args: unknown[]) => routerPostMock(...args),
    },
}));

// Wayfinder-typed compute() helper. Stub to a stable URL so the assertions
// below can match exactly.
vi.mock('@/routes/payroll/preview', () => ({
    compute: () => ({ url: '/payroll/preview/compute', method: 'post' }),
    show: () => ({ url: '/payroll/preview', method: 'get' }),
}));

import { usePayrollPreview } from '@/hooks/use-payroll-preview';
import type { PreviewSelection } from '@/types';

const SELECTION: PreviewSelection = {
    lms_staff_id: 42,
    frequency: 'monthly',
    year: 2026,
    month: 5,
};

describe('usePayrollPreview', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        routerPostMock.mockReset();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces multiple recompute calls within 250ms into one request', () => {
        const { result } = renderHook(() => usePayrollPreview());

        act(() => {
            result.current.requestRecompute(SELECTION);
            result.current.requestRecompute({ ...SELECTION, month: 6 });
            result.current.requestRecompute({ ...SELECTION, month: 7 });
        });

        expect(routerPostMock).not.toHaveBeenCalled();

        act(() => {
            vi.advanceTimersByTime(250);
        });

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        const [url, payload, options] = routerPostMock.mock.calls[0];
        expect(url).toBe('/payroll/preview/compute');
        expect(payload).toEqual({ ...SELECTION, month: 7 });
        expect(options).toMatchObject({
            preserveScroll: true,
            preserveState: true,
            only: [
                'selection',
                'noProfile',
                'currentPeriod',
                'priorPeriod',
                'computedAt',
            ],
        });
    });

    it('toggles isComputing around the request lifecycle', () => {
        const { result } = renderHook(() => usePayrollPreview());

        expect(result.current.isComputing).toBe(false);

        act(() => {
            result.current.requestRecompute(SELECTION);
        });

        // Still in the debounce window — no request yet, isComputing stays false.
        expect(result.current.isComputing).toBe(false);

        act(() => {
            vi.advanceTimersByTime(250);
        });

        expect(result.current.isComputing).toBe(true);
        expect(routerPostMock).toHaveBeenCalledTimes(1);

        // Inertia would call onFinish once the round-trip resolves; invoke
        // it directly to verify the flag clears.
        const options = routerPostMock.mock.calls[0][2] as {
            onFinish: () => void;
        };
        act(() => {
            options.onFinish();
        });

        expect(result.current.isComputing).toBe(false);
    });

    it('cancels the pending debounce timer on unmount', () => {
        const { result, unmount } = renderHook(() => usePayrollPreview());

        act(() => {
            result.current.requestRecompute(SELECTION);
        });

        unmount();

        act(() => {
            vi.advanceTimersByTime(500);
        });

        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it('cancel() drops a pending recompute without firing the request', () => {
        const { result } = renderHook(() => usePayrollPreview());

        act(() => {
            result.current.requestRecompute(SELECTION);
            result.current.cancel();
            vi.advanceTimersByTime(500);
        });

        expect(routerPostMock).not.toHaveBeenCalled();
    });
});
