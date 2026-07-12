<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridge Laravel session flash (`->with('success'|'error'|…)`) into Inertia's
 * `flash.toast` shape so the React `useFlashToast` hook fires a toast.
 *
 * The hook (resources/js/hooks/use-flash-toast.ts) only reacts to
 * `Inertia::flash('toast', …)`. The dominant convention across ~37 controllers
 * is Laravel session flash via `->with(...)`, which never populated
 * `flash.toast`. This middleware maps the first present session key (by
 * precedence) to a `{ type, message }` toast — matching the FlashToast type in
 * resources/js/types/ui.ts — before Inertia serializes the response.
 *
 * Only fires when the value is a non-empty string, and never sets a `toast`
 * itself when no matching session key exists, so Settings/SecurityController's
 * direct `Inertia::flash('toast', …)` is never disturbed or double-toasted.
 */
class HandleFlashToasts
{
    /**
     * Session-flash key → toast type, in precedence order.
     *
     * @var array<string, string>
     */
    private const KEY_TO_TYPE = [
        'success' => 'success',
        'error' => 'error',
        'warning' => 'warning',
        'info' => 'info',
        'message' => 'info',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $session = $request->session();

            foreach (self::KEY_TO_TYPE as $key => $type) {
                $value = $session->get($key);

                if (is_string($value) && $value !== '') {
                    Inertia::flash('toast', [
                        'type' => $type,
                        'message' => $value,
                    ]);

                    break;
                }
            }
        }

        return $next($request);
    }
}
