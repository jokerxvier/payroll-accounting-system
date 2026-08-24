<?php

declare(strict_types=1);

use App\Models\Pas\DocumentNumberSeries;
use App\Models\User;

/*
 * /admin/document-series (Phase 5 Slice 5).
 *
 * This file exists because its absence let a real bug through: the
 * controller, request, and sheet were all written against a `name` column
 * that does not exist — the table calls it `label` — and every other test
 * went through the factory, which had it right. Nothing failed until the
 * screen was actually driven.
 *
 * Pinned:
 *  - role gates: viewing is AccountingRoles::VIEW, editing is POST_LEDGER
 *  - a series round-trips through store and update with the real columns
 *  - the counter cannot be wound back over numbers already issued
 *  - the authorised range cannot be set to exclude the next number
 *  - one series per document type per school
 */

beforeEach(function (): void {
    DocumentNumberSeries::query()->withoutGlobalScopes()->delete();
});

function seriesAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function seriesPayload(array $overrides = []): array
{
    return array_merge([
        'document_type' => DocumentNumberSeries::TYPE_SALES_INVOICE,
        'label' => 'Sales invoices 2026',
        'prefix' => 'SI-',
        'padding' => 6,
        'next_number' => 1,
        'serial_start' => null,
        'serial_end' => null,
        'atp_number' => null,
        'permit_issued_at' => null,
        'is_active' => true,
    ], $overrides);
}

/* ── Access ─────────────────────────────────────────────────────────── */

it('lets every accounting-view role see the list', function (string $role) {
    $this->actingAs(seriesAuthAs($role))
        ->get(route('admin.document-series.index'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    $this->actingAs(seriesAuthAs($role))
        ->get(route('admin.document-series.index'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('lets a payroll officer look but not touch', function () {
    // Editing a series means editing the BIR permit details and the serial
    // range, which sits with the people who own the ledger.
    $officer = seriesAuthAs('payroll-officer');

    $this->actingAs($officer)->get(route('admin.document-series.index'))->assertOk();
    $this->actingAs($officer)
        ->post(route('admin.document-series.store'), seriesPayload())
        ->assertForbidden();
});

/* ── Round trip ─────────────────────────────────────────────────────── */

it('creates a series through the endpoint', function () {
    // The regression guard: this drives the real columns rather than the
    // factory's, so a controller written against a column that does not
    // exist fails here instead of in production.
    $this->actingAs(seriesAuthAs('accountant'))
        ->post(route('admin.document-series.store'), seriesPayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $series = DocumentNumberSeries::query()->firstOrFail();

    expect($series->label)->toBe('Sales invoices 2026')
        ->and($series->prefix)->toBe('SI-')
        ->and($series->next_number)->toBe(1)
        ->and($series->format(1))->toBe('SI-000001');
});

it('updates a series through the endpoint', function () {
    $series = DocumentNumberSeries::factory()->create(['next_number' => 5]);

    $this->actingAs(seriesAuthAs('accountant'))
        ->put(route('admin.document-series.update', $series), seriesPayload([
            'label' => 'Renamed',
            'next_number' => 5,
            'atp_number' => 'ATP-123456',
            'permit_issued_at' => '2026-01-15',
        ]))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $series->refresh();

    expect($series->label)->toBe('Renamed')
        ->and($series->atp_number)->toBe('ATP-123456')
        ->and($series->hasAuthorityToPrint())->toBeTrue();
});

it('renders the list with the real column names', function () {
    DocumentNumberSeries::factory()->create(['label' => 'Sales invoices 2026']);

    $this->actingAs(seriesAuthAs('accountant'))
        ->get(route('admin.document-series.index'))
        ->assertInertia(fn ($page) => $page
            ->where('series.0.label', 'Sales invoices 2026')
            ->where('series.0.next_formatted', 'SI-000001')
            ->where('series.0.has_authority_to_print', false)
            ->where('series.0.remaining_in_range', null));
});

/* ── Guards on the counter ──────────────────────────────────────────── */

it('refuses to wind the counter back over issued numbers', function () {
    // Moving it back would re-issue serials already printed on documents in
    // someone's hands.
    $series = DocumentNumberSeries::factory()->create(['next_number' => 50]);

    $this->actingAs(seriesAuthAs('accountant'))
        ->put(route('admin.document-series.update', $series), seriesPayload([
            'next_number' => 10,
        ]))
        ->assertSessionHasErrors('next_number');

    expect($series->refresh()->next_number)->toBe(50);
});

it('allows the counter to move forward', function () {
    // Skipping ahead is legitimate — it is how an operator accounts for a
    // spoiled pre-printed form.
    $series = DocumentNumberSeries::factory()->create(['next_number' => 50]);

    $this->actingAs(seriesAuthAs('accountant'))
        ->put(route('admin.document-series.update', $series), seriesPayload([
            'next_number' => 60,
        ]))
        ->assertSessionHasNoErrors();

    expect($series->refresh()->next_number)->toBe(60);
});

it('refuses a range that excludes the next number', function () {
    $series = DocumentNumberSeries::factory()->create(['next_number' => 100]);

    $this->actingAs(seriesAuthAs('accountant'))
        ->put(route('admin.document-series.update', $series), seriesPayload([
            'next_number' => 100,
            'serial_start' => 1,
            'serial_end' => 50,
        ]))
        ->assertSessionHasErrors('serial_end');
});

it('refuses a range that ends before it starts', function () {
    $this->actingAs(seriesAuthAs('accountant'))
        ->post(route('admin.document-series.store'), seriesPayload([
            'serial_start' => 500,
            'serial_end' => 100,
            'next_number' => 500,
        ]))
        ->assertSessionHasErrors('serial_end');
});

it('refuses a second series for the same document type', function () {
    // Two counters for one document type is how duplicate serials happen.
    DocumentNumberSeries::factory()->create();

    $this->actingAs(seriesAuthAs('accountant'))
        ->post(route('admin.document-series.store'), seriesPayload())
        ->assertSessionHasErrors('document_type');

    expect(DocumentNumberSeries::query()->count())->toBe(1);
});

it('accepts a series for a different document type', function () {
    DocumentNumberSeries::factory()->create();

    $this->actingAs(seriesAuthAs('accountant'))
        ->post(route('admin.document-series.store'), seriesPayload([
            'document_type' => DocumentNumberSeries::TYPE_BILL,
            'label' => 'Supplier bills',
            'prefix' => 'BILL-',
        ]))
        ->assertSessionHasNoErrors();

    expect(DocumentNumberSeries::query()->count())->toBe(2);
});

it('offers no delete route at all', function () {
    // A series that has issued numbers is the record of which serials went
    // out. Deactivating is the reversible equivalent.
    $series = DocumentNumberSeries::factory()->create();

    // 405, not 404: the URL exists for update, the verb simply is not
    // routed. That is the distinction worth pinning — a 404 here would mean
    // the resource route had been dropped entirely.
    $this->actingAs(seriesAuthAs('accountant'))
        ->delete("/admin/document-series/{$series->id}")
        ->assertMethodNotAllowed();
});
