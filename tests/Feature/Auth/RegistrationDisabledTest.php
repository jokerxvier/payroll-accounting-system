<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Guest self-registration is intentionally disabled.
 *
 * The LMS is the identity master (Fortify::authenticateUsing in
 * FortifyServiceProvider upserts pas_users from the LMS on every login), and
 * payroll-native operators (platform admins) are seeded via PlatformAdminSeeder.
 * There is no legitimate path for a guest to "sign up", so the
 * Features::registration() feature has been removed from config/fortify.php.
 *
 * The previous tests (which exercised the register-screen render and the
 * register-store happy path) have been replaced with route-existence
 * assertions that pin the disabled state. If a future iteration re-enables
 * registration, restore the prior test bodies (RegistrationTest.php) and UI
 * (auth/register page + canRegister gates) from git history.
 */

it('does not register the register route', function (): void {
    expect(Route::has('register'))->toBeFalse();
});

it('does not register the register.store route', function (): void {
    expect(Route::has('register.store'))->toBeFalse();
});

it('returns 404 when GETting /register', function (): void {
    $this->get('/register')->assertNotFound();
});

it('returns 404 when POSTing /register', function (): void {
    $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertNotFound();
});
