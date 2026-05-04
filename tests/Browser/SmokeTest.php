<?php

declare(strict_types=1);

/*
 * Canonical Pest 4 browser smoke test.
 *
 * Proves the browser-test infrastructure is wired end-to-end:
 *   - composer require pestphp/pest-plugin-browser ^4.0
 *   - npm install playwright@latest
 *   - npx playwright install chromium
 *   - tests/Pest.php binds Browser to TestCase + RefreshDatabase + RoleSeeder
 *   - phpunit.xml registers the Browser testsuite
 *
 * Pest's browser plugin spins up its own ephemeral PHP server bound to
 * 127.0.0.1 backed by the in-memory SQLite DB the Feature suite already uses
 * — no Herd, no playwright.config.ts, no separate server orchestration.
 *
 * Use this file as the template for any new browser test. Keep it green; if
 * the login page copy changes, update the assertion here first so future
 * regressions surface in the cheapest possible test.
 */

it('loads the login page', function () {
    visit('/login')
        ->assertSee('Email address')
        ->assertSee('Log in')
        ->assertNoJavaScriptErrors();
});
