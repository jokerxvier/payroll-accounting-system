import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useClipboard } from '@/hooks/use-clipboard';

/*
 * The reason this hook has a second path at all: `navigator.clipboard` exists
 * only in a secure context. Herd serves this app at `http://payroll-system.test`,
 * which is not one, so on every developer's machine — and on any deployment
 * without a certificate — the modern API is simply absent.
 */

function setClipboard(value: unknown): void {
    Object.defineProperty(navigator, 'clipboard', {
        value,
        configurable: true,
    });
}

afterEach(() => {
    setClipboard(undefined);
    vi.restoreAllMocks();
});

describe('useClipboard', () => {
    it('uses the Clipboard API when the context allows it', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        setClipboard({ writeText });

        const { result } = renderHook(() => useClipboard());

        let copied = false;
        await act(async () => {
            copied = await result.current[1]('hello');
        });

        expect(copied).toBe(true);
        expect(writeText).toHaveBeenCalledWith('hello');
        expect(result.current[0]).toBe('hello');
    });

    it('falls back to execCommand when there is no Clipboard API', async () => {
        setClipboard(undefined);
        const exec = vi.fn(() => true);
        document.execCommand = exec as unknown as typeof document.execCommand;

        const { result } = renderHook(() => useClipboard());

        let copied = false;
        await act(async () => {
            copied = await result.current[1]('over http');
        });

        expect(copied).toBe(true);
        expect(exec).toHaveBeenCalledWith('copy');
    });

    it('falls back when the Clipboard API rejects', async () => {
        // Permission refused, or the document was not focused — both are
        // ordinary, and neither should mean the operator gets nothing.
        setClipboard({ writeText: () => Promise.reject(new Error('denied')) });
        const exec = vi.fn(() => true);
        document.execCommand = exec as unknown as typeof document.execCommand;

        const { result } = renderHook(() => useClipboard());

        let copied = false;
        await act(async () => {
            copied = await result.current[1]('denied but copied');
        });

        expect(copied).toBe(true);
        expect(exec).toHaveBeenCalledWith('copy');
    });

    it('reports failure rather than throwing when neither route works', async () => {
        // The caller then shows the text instead, which is the difference
        // between a button that does nothing and one that hands you the link.
        setClipboard(undefined);
        document.execCommand = (() =>
            false) as unknown as typeof document.execCommand;

        const { result } = renderHook(() => useClipboard());

        let copied = true;
        await act(async () => {
            copied = await result.current[1]('nowhere to go');
        });

        expect(copied).toBe(false);
        expect(result.current[0]).toBeNull();
    });

    it('leaves no stray field behind after copying', async () => {
        // The fallback has to put a real textarea on the page to select it —
        // one that stays is a focus trap and a layout bug.
        setClipboard(undefined);
        document.execCommand = (() =>
            true) as unknown as typeof document.execCommand;

        const { result } = renderHook(() => useClipboard());

        await act(async () => {
            await result.current[1]('tidy up');
        });

        expect(document.querySelectorAll('textarea')).toHaveLength(0);
    });

    it('refuses an empty string without touching the clipboard', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        setClipboard({ writeText });

        const { result } = renderHook(() => useClipboard());

        let copied = true;
        await act(async () => {
            copied = await result.current[1]('');
        });

        expect(copied).toBe(false);
        expect(writeText).not.toHaveBeenCalled();
    });
});
