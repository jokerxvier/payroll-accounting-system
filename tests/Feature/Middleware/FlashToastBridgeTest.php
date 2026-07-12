<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/*
 * HandleFlashToasts bridges Laravel session flash (`->with('success'|…)`)
 * into Inertia's `flash.toast` so the React useFlashToast hook fires a toast.
 *
 * ~37 controllers redirect with `->with('success'|'error'|…)`; without this
 * bridge none of them toast (the hook only reads `flash.toast`, produced by
 * `Inertia::flash('toast', …)`). These tests pin the mapping and the no-op
 * case so a controller using `Inertia::flash('toast')` directly is never
 * double-toasted.
 *
 * We follow the redirect and assert on the target GET's Inertia page-level
 * `flash` key (via `hasFlash`) — that is exactly the `event.detail.flash`
 * shape useFlashToast reads. `assertInertiaFlash` is NOT used here: it reads
 * session flash, which Inertia's Response::resolveFlashData already pulled
 * into the page before the assertion runs.
 *
 * Routes are registered inline under the `web` group so the appended
 * HandleFlashToasts + HandleInertiaRequests middleware actually run. We
 * redirect to a literal path (not a named route) because names added after
 * boot don't always register in the UrlGenerator's name map.
 */

beforeEach(function (): void {
    Route::middleware('web')->group(function (): void {
        Route::get('/toast-bridge/target', fn () => Inertia::render('dashboard'));

        // {message} is a single non-slash segment; keep test messages simple.
        Route::get('/toast-bridge/flash/{type}/{message}', function (string $type, string $message) {
            return redirect('/toast-bridge/target')->with($type, $message);
        });

        Route::get('/toast-bridge/plain', fn () => Inertia::render('dashboard'));
    });
});

it('maps a success session flash to a success toast', function () {
    $this->followingRedirects()
        ->get('/toast-bridge/flash/success/Saved')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.type', 'success')
            ->hasFlash('toast.message', 'Saved'));
});

it('maps an error session flash to an error toast', function () {
    $this->followingRedirects()
        ->get('/toast-bridge/flash/error/Broke')
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.type', 'error')
            ->hasFlash('toast.message', 'Broke'));
});

it('maps a warning session flash to a warning toast', function () {
    $this->followingRedirects()
        ->get('/toast-bridge/flash/warning/Careful')
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.type', 'warning')
            ->hasFlash('toast.message', 'Careful'));
});

it('maps info and message session flash to an info toast', function (string $key) {
    $this->followingRedirects()
        ->get('/toast-bridge/flash/'.$key.'/Heads-up')
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.type', 'info')
            ->hasFlash('toast.message', 'Heads-up'));
})->with(['info', 'message']);

it('sets no toast when there is no session flash', function () {
    $this->get('/toast-bridge/plain')
        ->assertInertia(fn (Assert $page) => $page->missingFlash('toast'));
});

it('prefers success over lower-precedence keys', function () {
    Route::middleware('web')->get('/toast-bridge/multi', function () {
        return redirect('/toast-bridge/target')
            ->with('error', 'err')
            ->with('success', 'ok');
    });

    $this->followingRedirects()
        ->get('/toast-bridge/multi')
        ->assertInertia(fn (Assert $page) => $page
            ->hasFlash('toast.type', 'success')
            ->hasFlash('toast.message', 'ok'));
});
