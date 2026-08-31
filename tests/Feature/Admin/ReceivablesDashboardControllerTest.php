<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * The invoice dashboard page.
 *
 * Its figures are covered by `ReceivablesServiceTest`. What is left here is
 * who may read them — and this page is gated differently from the accounting
 * one on purpose: it authorises on Invoice, not JournalEntry, so an officer
 * who chases payments is not thereby handed the school's profit.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);
});

function invoiceDashboardAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('lets every accounting-view role read it', function (string $role) {
    $this->actingAs(invoiceDashboardAuthAs($role))
        ->get(route('admin.reports.invoice-dashboard'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    $this->actingAs(invoiceDashboardAuthAs($role))
        ->get(route('admin.reports.invoice-dashboard'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('sends a guest to log in', function () {
    $this->get(route('admin.reports.invoice-dashboard'))
        ->assertRedirect('/login');
});

it('ships every tile, both charts and the table', function () {
    $this->actingAs(invoiceDashboardAuthAs('accountant'))
        ->get(route('admin.reports.invoice-dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('summary.invoiced_centavos')
            ->has('summary.collected_centavos')
            ->has('summary.outstanding_centavos')
            ->has('summary.overdue_centavos')
            ->has('summary.aging', 5)
            ->has('summary.statuses', 4)
            ->has('summary.monthly')
            ->has('summary.top_outstanding'));
});

it('ages as at today, not the end of the range', function () {
    // "How overdue is this" is a question about now. Asking it as at a past
    // date would report debts as current that have since gone bad.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-15 09:00', 'Asia/Manila'));

    $this->actingAs(invoiceDashboardAuthAs('accountant'))
        ->get(route('admin.reports.invoice-dashboard', [
            'preset' => 'custom',
            'from' => '2026-01-01',
            'to' => '2026-06-30',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('summary.to', '2026-06-30')
            ->where('summary.as_of', '2026-09-15'));

    CarbonImmutable::setTestNow();
});

it('narrows to this month in Manila time, not UTC', function () {
    // 08:00 Manila on the 1st is 00:00 UTC on the 1st; an hour earlier and UTC
    // is still the previous month, which would resolve "This month" to all of
    // the month before.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 07:00', 'Asia/Manila'));

    $this->actingAs(invoiceDashboardAuthAs('accountant'))
        ->get(route('admin.reports.invoice-dashboard', ['preset' => 'month']))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-09-01')
            ->where('filters.to', '2026-09-30'));

    CarbonImmutable::setTestNow();
});

it('flips an inverted range instead of returning nothing', function () {
    $this->actingAs(invoiceDashboardAuthAs('accountant'))
        ->get(route('admin.reports.invoice-dashboard', [
            'preset' => 'custom',
            'from' => '2026-08-31',
            'to' => '2026-08-01',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.from', '2026-08-01')
            ->where('filters.to', '2026-08-31'));
});
