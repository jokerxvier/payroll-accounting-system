<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;

/*
 * DemoUsersSeeder coverage.
 *
 * Locks in the demo-account contract that the login page's "Demo as X"
 * buttons depend on:
 *   - the four accounts exist with the expected emails after seeding,
 *   - each carries the correct Spatie payroll role,
 *   - the seeder is idempotent (re-running keeps the same row ids and
 *     does not duplicate),
 *   - each account can authenticate via Fortify and reach /dashboard,
 *   - the on-login role-sync listener does NOT trample the seeded role
 *     for either the mapped (super-admin) or the null-role-id (auditor)
 *     case.
 */

const DEMO_EMAILS = [
    'super-admin@demo.test',
    'payroll-officer@demo.test',
    'hr@demo.test',
    'auditor@demo.test',
];

it('creates the four demo accounts on first run', function () {
    $this->seed(DemoUsersSeeder::class);

    foreach (DEMO_EMAILS as $email) {
        expect(User::query()->where('email', $email)->exists())->toBeTrue();
    }

    expect(User::query()->whereIn('email', DEMO_EMAILS)->count())->toBe(4);
});

it('assigns the correct Spatie role to each demo account', function () {
    $this->seed(DemoUsersSeeder::class);

    $expectations = [
        'super-admin@demo.test' => 'super-admin',
        'payroll-officer@demo.test' => 'payroll-officer',
        'hr@demo.test' => 'hr',
        'auditor@demo.test' => 'auditor',
    ];

    foreach ($expectations as $email => $role) {
        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->hasRole($role))->toBeTrue();
    }
});

it('is idempotent — re-running the seeder keeps the same row ids and creates no duplicates', function () {
    $this->seed(DemoUsersSeeder::class);

    $idsAfterFirst = User::query()
        ->whereIn('email', DEMO_EMAILS)
        ->orderBy('email')
        ->pluck('id')
        ->all();

    $this->seed(DemoUsersSeeder::class);

    $idsAfterSecond = User::query()
        ->whereIn('email', DEMO_EMAILS)
        ->orderBy('email')
        ->pluck('id')
        ->all();

    expect($idsAfterSecond)->toBe($idsAfterFirst);

    foreach (DEMO_EMAILS as $email) {
        expect(User::query()->where('email', $email)->count())->toBe(1);
    }
});

it('preserves Spatie roles on a second seeder run', function () {
    $this->seed(DemoUsersSeeder::class);
    $this->seed(DemoUsersSeeder::class);

    foreach (
        [
            'super-admin@demo.test' => 'super-admin',
            'payroll-officer@demo.test' => 'payroll-officer',
            'hr@demo.test' => 'hr',
            'auditor@demo.test' => 'auditor',
        ] as $email => $role
    ) {
        $user = User::query()->where('email', $email)->firstOrFail();
        expect($user->hasRole($role))->toBeTrue();
        // No duplicate role assignments either.
        expect($user->roles()->count())->toBe(1);
    }
});

it('lets each demo account log in via the Fortify endpoint and reach the dashboard', function () {
    $this->seed(DemoUsersSeeder::class);

    foreach (DEMO_EMAILS as $email) {
        $response = $this->post(route('login.store'), [
            'email' => $email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        $this->post(route('logout'));
    }
});

it('preserves the auditor role across the on-login role-sync listener', function () {
    $this->seed(DemoUsersSeeder::class);

    $auditor = User::query()->where('email', 'auditor@demo.test')->firstOrFail();
    expect($auditor->hasRole('auditor'))->toBeTrue();

    // Simulate the Login event the listener subscribes to. With role_id NULL
    // the listener returns early and must not touch the user's role set.
    Event::dispatch(new Login('web', $auditor, false));

    expect($auditor->fresh()->hasRole('auditor'))->toBeTrue();
});

it('preserves the super-admin role through the on-login mapping', function () {
    $this->seed(DemoUsersSeeder::class);

    $superAdmin = User::query()->where('email', 'super-admin@demo.test')->firstOrFail();

    Event::dispatch(new Login('web', $superAdmin, false));

    expect($superAdmin->fresh()->hasRole('super-admin'))->toBeTrue();
});

it('preserves the payroll-officer and hr roles through the on-login mapping', function () {
    $this->seed(DemoUsersSeeder::class);

    $officer = User::query()->where('email', 'payroll-officer@demo.test')->firstOrFail();
    $hr = User::query()->where('email', 'hr@demo.test')->firstOrFail();

    Event::dispatch(new Login('web', $officer, false));
    Event::dispatch(new Login('web', $hr, false));

    expect($officer->fresh()->hasRole('payroll-officer'))->toBeTrue();
    expect($hr->fresh()->hasRole('hr'))->toBeTrue();
});
