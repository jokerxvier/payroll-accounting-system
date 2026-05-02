<?php

declare(strict_types=1);

use App\Models\User;

/*
 * The user's identity is owned by the LMS and read-only here. The profile
 * flow accordingly:
 *  - renders an informational page (GET)
 *  - hard-blocks updates and account deletion (PATCH/DELETE → 403)
 */

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information cannot be updated because LMS owns identity', function () {
    $user = User::factory()->create();
    $originalName = $user->name;
    $originalEmail = $user->email;

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response->assertForbidden();

    $user->refresh();
    expect($user->name)->toBe($originalName);
    expect($user->email)->toBe($originalEmail);
});

test('account cannot be deleted because LMS owns the user lifecycle', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response->assertForbidden();

    expect($user->fresh())->not->toBeNull();
});
