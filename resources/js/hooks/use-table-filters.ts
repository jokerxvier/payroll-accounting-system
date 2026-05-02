import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface UseTableFiltersOptions {
    debounceMs?: number;
    only?: string[];
}

/**
 * URL-synced filter state for Inertia table pages.
 *
 * - `apply(partial)` merges the patch and visits immediately. Use for selects/toggles.
 * - `applyDebounced(partial)` merges and visits after `debounceMs` (default 300). Use for text search.
 * - Empty / null / undefined values are stripped from the URL.
 *
 * The first arg is the initial filters (typically the controller's `filters` prop).
 * The second arg is the path to visit (typically `route('employees.index')` or a Wayfinder url helper).
 */
export function useTableFilters<T extends object>(
    initial: T,
    path: string,
    options: UseTableFiltersOptions = {},
) {
    const { debounceMs = 300, only } = options;

    const [filters, setFilters] = useState<T>(initial);
    const debounceRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(
        () => () => {
            if (debounceRef.current) {
                clearTimeout(debounceRef.current);
            }
        },
        [],
    );

    const visit = useCallback(
        (next: T) => {
            const query: Record<string, string | number | boolean> = {};

            for (const [k, v] of Object.entries(
                next as Record<string, unknown>,
            )) {
                if (
                    v !== undefined &&
                    v !== null &&
                    v !== '' &&
                    (typeof v === 'string' ||
                        typeof v === 'number' ||
                        typeof v === 'boolean')
                ) {
                    query[k] = v;
                }
            }

            router.get(path, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                ...(only ? { only } : {}),
            });
        },
        [path, only],
    );

    const apply = useCallback(
        (patch: Partial<T>) => {
            setFilters((prev) => {
                const next = { ...prev, ...patch } as T;

                if (debounceRef.current) {
                    clearTimeout(debounceRef.current);
                    debounceRef.current = null;
                }

                visit(next);

                return next;
            });
        },
        [visit],
    );

    const applyDebounced = useCallback(
        (patch: Partial<T>) => {
            setFilters((prev) => {
                const next = { ...prev, ...patch } as T;

                if (debounceRef.current) {
                    clearTimeout(debounceRef.current);
                }

                debounceRef.current = setTimeout(() => {
                    visit(next);
                    debounceRef.current = null;
                }, debounceMs);

                return next;
            });
        },
        [visit, debounceMs],
    );

    const reset = useCallback(() => {
        if (debounceRef.current) {
            clearTimeout(debounceRef.current);
            debounceRef.current = null;
        }

        const empty = {} as T;
        setFilters(empty);
        visit(empty);
    }, [visit]);

    return { filters, apply, applyDebounced, reset };
}
