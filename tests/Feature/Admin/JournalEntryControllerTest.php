<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;

/*
 * /admin/journal-entries (Phase 5 Slice 2).
 *
 * Pinned:
 *  - maker-checker: MANAGE drafts, POST_LEDGER commits and reverses
 *  - the FormRequest surfaces an imbalance as a field error before the
 *    action ever sees it
 *  - a posted entry cannot be edited or deleted through any route
 *  - domain refusals come back as flash errors, not 500s
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    AccountingPeriod::factory()->create([
        'code' => '2026-08',
        'start_date' => '2026-08-01',
        'end_date' => '2026-08-31',
    ]);

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    $this->income = ChartOfAccount::factory()->income()->create(['code' => '4100']);
});

function journalAuthAs(string $payrollRole): User
{
    $user = User::factory()->create();
    $user->syncRoles([$payrollRole]);

    return $user;
}

/** @return array<string, mixed> */
function validEntryPayload(array $overrides = []): array
{
    return array_merge([
        'date' => '2026-08-15',
        'reference' => 'REF-001',
        'narration' => 'Tuition collected',
        'lines' => [
            ['account_id' => test()->cash->id, 'debit_centavos' => 500_000, 'credit_centavos' => 0, 'description' => 'Cash in'],
            ['account_id' => test()->income->id, 'debit_centavos' => 0, 'credit_centavos' => 500_000, 'description' => 'Tuition'],
        ],
    ], $overrides);
}

function draftWithLines(): JournalEntry
{
    $entry = JournalEntry::factory()->create(['date' => '2026-08-15']);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id, 'account_id' => test()->cash->id,
        'debit_centavos' => 500_000, 'credit_centavos' => 0, 'line_number' => 1,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $entry->id, 'account_id' => test()->income->id,
        'debit_centavos' => 0, 'credit_centavos' => 500_000, 'line_number' => 2,
    ]);

    return $entry->fresh();
}

/* ── Access ─────────────────────────────────────────────────────────── */

it('lets viewers read the journal', function (string $role) {
    JournalEntry::factory()->posted()->create();

    $this->actingAs(journalAuthAs($role))
        ->get('/admin/journal-entries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/journal/index', false)
            ->has('entries.data', 1));
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('locks an employee out of the journal', function () {
    $this->actingAs(journalAuthAs('employee'))
        ->get('/admin/journal-entries')
        ->assertForbidden();
});

it('requires authentication', function () {
    $this->get('/admin/journal-entries')->assertRedirect('/login');
});

it('lets an auditor read but not draft', function () {
    $auditor = journalAuthAs('auditor');

    $this->actingAs($auditor)->get('/admin/journal-entries')->assertOk();
    $this->actingAs($auditor)->get('/admin/journal-entries/create')->assertForbidden();
    $this->actingAs($auditor)
        ->post('/admin/journal-entries', validEntryPayload())
        ->assertForbidden();
});

/* ── Drafting ───────────────────────────────────────────────────────── */

it('saves a balanced draft without posting it', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload())
        ->assertRedirect();

    $entry = JournalEntry::query()->firstOrFail();

    expect($entry->status)->toBe(JournalEntry::STATUS_DRAFT)
        // A draft has not reached the ledger, so it burns no number and
        // belongs to no period yet.
        ->and($entry->entry_number)->toBeNull()
        ->and($entry->accounting_period_id)->toBeNull()
        ->and($entry->lines()->count())->toBe(2);
});

it('rejects an unbalanced draft with a field error', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 500_000, 'credit_centavos' => 0],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => 400_000],
            ],
        ]))
        ->assertSessionHasErrors('lines');

    expect(JournalEntry::query()->count())->toBe(0);
});

it('rejects a single-line entry', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 500_000, 'credit_centavos' => 0],
            ],
        ]))
        ->assertSessionHasErrors('lines');
});

it('rejects a line carrying both a debit and a credit', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 500_000, 'credit_centavos' => 500_000],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => 500_000],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit_centavos');
});

it('rejects an empty line', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 0, 'credit_centavos' => 0],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => 0],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit_centavos');
});

it('rejects a negative amount', function () {
    $this->actingAs(journalAuthAs('accountant'))
        ->post('/admin/journal-entries', validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => -500_000, 'credit_centavos' => 0],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => -500_000],
            ],
        ]))
        ->assertSessionHasErrors('lines.0.debit_centavos');
});

it('replaces lines wholesale on update', function () {
    $entry = draftWithLines();

    $this->actingAs(journalAuthAs('accountant'))
        ->patch("/admin/journal-entries/{$entry->id}", validEntryPayload([
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 250_000, 'credit_centavos' => 0],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => 250_000],
            ],
        ]))
        ->assertRedirect();

    $lines = $entry->fresh()->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines->sum('debit_centavos'))->toBe(250_000)
        ->and($lines->pluck('line_number')->all())->toBe([1, 2]);
});

it('deletes a draft and its lines', function () {
    $entry = draftWithLines();

    $this->actingAs(journalAuthAs('accountant'))
        ->delete("/admin/journal-entries/{$entry->id}")
        ->assertRedirect('/admin/journal-entries');

    expect(JournalEntry::query()->count())->toBe(0)
        ->and(JournalEntryLine::query()->count())->toBe(0);
});

/* ── Posting ────────────────────────────────────────────────────────── */

it('posts a draft as a ledger role', function () {
    $entry = draftWithLines();
    $actor = journalAuthAs('accountant');

    $this->actingAs($actor)
        ->post("/admin/journal-entries/{$entry->id}/post")
        ->assertRedirect()
        ->assertSessionHas('success');

    $posted = $entry->fresh();

    expect($posted->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($posted->entry_number)->toBe('JE-2026-00001')
        ->and($posted->accounting_period_id)->not()->toBeNull()
        ->and($posted->posted_by_user_id)->toBe($actor->getKey());
});

it('keeps posting away from the drafting-only role', function () {
    // payroll-officer can draft the figures but not commit them — the same
    // maker-checker split the payroll run lifecycle uses.
    $entry = draftWithLines();

    $this->actingAs(journalAuthAs('payroll-officer'))
        ->post("/admin/journal-entries/{$entry->id}/post")
        ->assertForbidden();

    expect($entry->fresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('surfaces a closed period as guidance rather than a crash', function () {
    AccountingPeriod::query()->update([
        'status' => AccountingPeriod::STATUS_CLOSED,
        'closed_at' => now(),
    ]);

    $entry = draftWithLines();

    $this->actingAs(journalAuthAs('accountant'))
        ->post("/admin/journal-entries/{$entry->id}/post")
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($entry->fresh()->status)->toBe(JournalEntry::STATUS_DRAFT);
});

it('refuses to edit or delete a posted entry', function () {
    $entry = draftWithLines();
    $this->actingAs(journalAuthAs('accountant'))
        ->post("/admin/journal-entries/{$entry->id}/post");

    $user = journalAuthAs('accountant');

    $this->actingAs($user)->get("/admin/journal-entries/{$entry->id}/edit")->assertForbidden();
    $this->actingAs($user)
        ->patch("/admin/journal-entries/{$entry->id}", validEntryPayload())
        ->assertForbidden();
    $this->actingAs($user)->delete("/admin/journal-entries/{$entry->id}")->assertForbidden();

    expect($entry->fresh()->status)->toBe(JournalEntry::STATUS_POSTED);
});

/* ── Reversal ───────────────────────────────────────────────────────── */

it('reverses a posted entry and leaves both on the books', function () {
    $entry = draftWithLines();
    $actor = journalAuthAs('accountant');
    $this->actingAs($actor)->post("/admin/journal-entries/{$entry->id}/post");

    $this->actingAs($actor)
        ->post("/admin/journal-entries/{$entry->id}/reverse", ['reason' => 'Keyed twice'])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(JournalEntry::query()->posted()->count())->toBe(2)
        ->and($entry->fresh()->status)->toBe(JournalEntry::STATUS_POSTED)
        ->and($entry->fresh()->hasBeenReversed())->toBeTrue();

    $reversal = JournalEntry::query()->where('reversal_of_entry_id', $entry->id)->firstOrFail();
    expect($reversal->narration)->toContain('Keyed twice');
});

it('keeps reversing away from the drafting-only role', function () {
    $entry = draftWithLines();
    $this->actingAs(journalAuthAs('accountant'))->post("/admin/journal-entries/{$entry->id}/post");

    $this->actingAs(journalAuthAs('payroll-officer'))
        ->post("/admin/journal-entries/{$entry->id}/reverse")
        ->assertForbidden();
});

it('refuses a second reversal', function () {
    $entry = draftWithLines();
    $actor = journalAuthAs('accountant');
    $this->actingAs($actor)->post("/admin/journal-entries/{$entry->id}/post");
    $this->actingAs($actor)->post("/admin/journal-entries/{$entry->id}/reverse");

    $this->actingAs($actor)
        ->post("/admin/journal-entries/{$entry->id}/reverse")
        ->assertForbidden();

    expect(JournalEntry::query()->count())->toBe(2);
});

it('ships per-entry permissions with the detail page', function () {
    $entry = draftWithLines();

    $this->actingAs(journalAuthAs('accountant'))
        ->get("/admin/journal-entries/{$entry->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/journal/show', false)
            ->where('entry.can.post', true)
            ->where('entry.can.reverse', false)
            ->where('entry.can.update', true)
            ->has('entry.lines', 2));
});

/* ── Per-row permissions on the list ────────────────────────────────── */

it('ships per-row permissions with the index', function () {
    $draft = draftWithLines();

    $this->actingAs(journalAuthAs('accountant'))
        ->get('/admin/journal-entries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/journal/index', false)
            ->where('entries.data.0.can.update', true)
            ->where('entries.data.0.can.delete', true)
            ->where('entries.data.0.can.reverse', false));

    expect($draft->fresh()->isMutable())->toBeTrue();
});

it('offers no edit or delete on a posted row, even to a platform admin', function () {
    // Regression guard for 9c8e385: Gate::before grants a platform admin every
    // ability, so asking the policy alone would put Edit and Delete on an
    // entry that is already posted — controls the endpoint then refuses.
    $entry = draftWithLines();
    $this->actingAs(journalAuthAs('accountant'))
        ->post("/admin/journal-entries/{$entry->id}/post");

    $platformAdmin = User::factory()->withoutLmsMirror()->create();
    $platformAdmin->syncRoles(['platform-admin']);

    $this->actingAs($platformAdmin->fresh())
        ->get('/admin/journal-entries')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/journal/index', false)
            ->where('entries.data.0.can.update', false)
            ->where('entries.data.0.can.delete', false)
            // Reversing IS legal on a posted entry, so it stays available.
            ->where('entries.data.0.can.reverse', true));
});

/* ── Draft totals ───────────────────────────────────────────────────── */

it('reports a draft total from its lines, not the unwritten columns', function () {
    // PostJournalEntry only writes total_*_centavos at post time, so a draft
    // has zeroes stored. The detail footer sits directly under the lines, so
    // reading the stored column would show 0.00 beneath lines summing to
    // 5,000.00 — on the screen where the figures get checked before posting.
    $draft = draftWithLines();

    expect($draft->total_debit_centavos)->toBe(0);

    $this->actingAs(journalAuthAs('accountant'))
        ->get("/admin/journal-entries/{$draft->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entry.total_debit_centavos', 500_000)
            ->where('entry.total_credit_centavos', 500_000));
});

it('reports a posted total identically whichever source is used', function () {
    // For a posted entry the computed and stored figures must agree — the
    // action derived the stored columns from these same lines.
    $entry = draftWithLines();
    $this->actingAs(journalAuthAs('accountant'))
        ->post("/admin/journal-entries/{$entry->id}/post");

    $posted = $entry->fresh();

    $this->actingAs(journalAuthAs('accountant'))
        ->get("/admin/journal-entries/{$posted->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('entry.total_debit_centavos', $posted->total_debit_centavos)
            ->where('entry.total_credit_centavos', $posted->total_credit_centavos));
});
