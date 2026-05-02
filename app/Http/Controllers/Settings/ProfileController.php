<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profile settings controller.
 *
 * The user's identity (name, email, role, department) lives in the LMS and
 * is read-only at this app's boundary. The starter-kit's mutable profile
 * flow (update name/email, delete account) is therefore not supported here:
 *
 *  - `edit` still renders the profile page so the user can view their
 *    identity and the list of payroll-owned fields they CAN edit elsewhere.
 *  - `update` and `destroy` are hard-blocked with HTTP 403. Identity edits
 *    must be performed in the LMS itself.
 *
 * In a later week, payroll-owned profile fields (TIN, SSS, PhilHealth,
 * Pag-IBIG, bank, salary configuration) will be editable through dedicated
 * controllers operating against `pas_employee_profiles` only.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Profile updates are not permitted — the LMS owns user identity.
     */
    public function update(Request $request): RedirectResponse
    {
        abort(403, 'Profile fields are managed in the LMS and cannot be edited here.');
    }

    /**
     * Account deletion is not permitted — the LMS owns the user lifecycle.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'Account deletion is managed in the LMS and cannot be performed here.');
    }
}
