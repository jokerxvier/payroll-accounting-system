// Credit: https://usehooks-ts.com/
import { useState } from 'react';

export type CopiedValue = string | null;
export type CopyFn = (text: string) => Promise<boolean>;
export type UseClipboardReturn = [CopiedValue, CopyFn];

/**
 * Put text on the clipboard, by whichever route the browser allows.
 *
 * `navigator.clipboard` exists only in a **secure context** — HTTPS, or
 * localhost. A Laravel app served by Herd at `http://something.test` is
 * neither, so the modern API is simply `undefined` there and a bare
 * `navigator.clipboard.writeText(...)` throws before it can fail politely.
 * That is not an edge case for this app; it is every developer's machine and
 * any deployment that has not been given a certificate.
 *
 * So there is a second path: a hidden textarea and `document.execCommand`.
 * Deprecated, works everywhere, and needs no permission. It also has one hard
 * requirement of its own — it must run inside the user gesture that asked for
 * the copy. A copy that waits on a round trip first has already left that
 * window, which is why a caller that mints something server-side should hold
 * the value it copies rather than fetching it mid-gesture.
 *
 * Returns false rather than throwing when neither route works, so a caller can
 * show the text and let the operator copy it by hand.
 */
export function useClipboard(): UseClipboardReturn {
    const [copiedText, setCopiedText] = useState<CopiedValue>(null);

    const copy: CopyFn = async (text) => {
        if (text === '') {
            return false;
        }

        if (navigator?.clipboard) {
            try {
                await navigator.clipboard.writeText(text);
                setCopiedText(text);

                return true;
            } catch {
                // Permission refused, or the document was not focused. The
                // legacy path below still stands a chance.
            }
        }

        if (copyByExecCommand(text)) {
            setCopiedText(text);

            return true;
        }

        setCopiedText(null);

        return false;
    };

    return [copiedText, copy];
}

/**
 * The pre-Clipboard-API route: select text in an offscreen field and copy it.
 *
 * Positioned rather than hidden — `display: none` and `visibility: hidden`
 * cannot be selected, so the field has to be genuinely on the page and merely
 * out of sight. `readOnly` keeps a mobile keyboard from appearing during the
 * moment it is focused.
 */
function copyByExecCommand(text: string): boolean {
    if (typeof document === 'undefined' || !document.body) {
        return false;
    }

    const field = document.createElement('textarea');

    field.value = text;
    field.readOnly = true;
    field.setAttribute('aria-hidden', 'true');
    field.style.position = 'fixed';
    field.style.top = '0';
    field.style.left = '-9999px';

    document.body.appendChild(field);

    try {
        field.select();
        field.setSelectionRange(0, text.length);

        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        document.body.removeChild(field);
    }
}
