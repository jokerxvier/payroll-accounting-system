import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { compute as previewCompute } from '@/routes/payroll/preview';
import type { PreviewSelection } from '@/types';

/**
 * Debounce delay before re-issuing a compute request after a control change.
 *
 * Stage A Decision 5 (PM brief): 250ms is the sweet spot between responsive
 * (a held arrow key on the year input doesn't fire a request per keystroke)
 * and snappy (the next compute lands within ~half a second of the user
 * stopping interaction).
 */
const COMPUTE_DEBOUNCE_MS = 250;

/**
 * Inertia partial-reload key set the controller mutates between
 * `show()` and `compute()`. Listing them here keeps the wire payload tight —
 * the static props (`employees`, `frequencyOptions`, `defaultPeriod`) are not
 * re-serialized on every keystroke.
 */
const COMPUTE_PARTIAL_KEYS = [
    'selection',
    'noProfile',
    'currentPeriod',
    'priorPeriod',
    'computedAt',
] as const;

export interface UsePayrollPreviewReturn {
    /**
     * `true` between the moment the debounce timer fires and the moment
     * Inertia finishes the round-trip (success or failure). The page uses
     * this to overlay a `<Skeleton>` on the breakdown cards so stale
     * numbers aren't read while a fresh compute is in flight.
     */
    isComputing: boolean;
    /**
     * Schedules a recompute against the supplied selection. Multiple calls
     * within `COMPUTE_DEBOUNCE_MS` collapse into one — the selection from
     * the last call wins.
     *
     * The compute endpoint requires a non-zero `lms_staff_id`; callers
     * should guard before invoking. The hook does not silently no-op so
     * misuse surfaces as a 422 in the dev tools.
     */
    requestRecompute: (selection: PreviewSelection) => void;
    /**
     * Cancels any pending debounce timer without firing the request.
     * Useful when the user clears the employee selection mid-debounce.
     */
    cancel: () => void;
}

/**
 * Owns the debounced Inertia round-trip that powers the real-time preview.
 *
 * Pattern: a single ref holds the pending timer. Every call clears the
 * previous timer (last-write-wins semantics) and schedules a fresh one. The
 * `useEffect` cleanup clears the ref on unmount so a navigation that lands
 * mid-debounce doesn't leak a stray request.
 *
 * Why a hook (not a Zustand store): the request lifecycle is local to the
 * preview page. Nothing else in the app cares about the in-flight state, and
 * promoting it to global state would couple unrelated views to a transient
 * concern.
 */
export function usePayrollPreview(): UsePayrollPreviewReturn {
    const [isComputing, setIsComputing] = useState(false);
    const debounceTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const cancel = useCallback(() => {
        if (debounceTimerRef.current !== null) {
            clearTimeout(debounceTimerRef.current);
            debounceTimerRef.current = null;
        }
    }, []);

    const requestRecompute = useCallback(
        (selection: PreviewSelection) => {
            cancel();

            debounceTimerRef.current = setTimeout(() => {
                debounceTimerRef.current = null;
                setIsComputing(true);

                router.post(
                    previewCompute().url,
                    { ...selection },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        only: [...COMPUTE_PARTIAL_KEYS],
                        onFinish: () => setIsComputing(false),
                    },
                );
            }, COMPUTE_DEBOUNCE_MS);
        },
        [cancel],
    );

    useEffect(() => {
        // Tear-down: never leave a timer pointing at a stale closure after
        // the page unmounts. Inertia visit cancellation is implicit — if the
        // request is already in flight, the response will be ignored when
        // its target component is no longer mounted.
        return () => {
            if (debounceTimerRef.current !== null) {
                clearTimeout(debounceTimerRef.current);
                debounceTimerRef.current = null;
            }
        };
    }, []);

    return { isComputing, requestRecompute, cancel };
}
