<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Phase A.2: password reset is intentionally disabled.
 *
 * Per docs/improvement/plan-2.md ("Open question — password resets" → option
 * (b)), the LMS is the identity master. Password resets are handled
 * out-of-band by the LMS admin; the payroll app does not register password
 * reset routes nor surface a /forgot-password form once Phase A.2 ships.
 *
 * The previous tests (which exercised the request, screen, valid-token,
 * invalid-token, and link-via-notification flows) have been replaced with
 * route-existence assertions that pin the disabled state. If a future
 * iteration re-enables resets (e.g., proxying to LMS), restore the prior
 * test bodies from git history at f200465 or earlier.
 */

it('does not register the password.request route', function (): void {
    expect(Route::has('password.request'))->toBeFalse();
});

it('does not register the password.email route', function (): void {
    expect(Route::has('password.email'))->toBeFalse();
});

it('does not register the password.reset route', function (): void {
    expect(Route::has('password.reset'))->toBeFalse();
});

it('does not register the password.update route', function (): void {
    expect(Route::has('password.update'))->toBeFalse();
});
