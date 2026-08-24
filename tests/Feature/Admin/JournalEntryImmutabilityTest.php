<?php

declare(strict_types=1);

use App\Actions\Accounting\PostJournalEntry;
use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * A posted journal entry is immutable — for everyone, including a platform
 * admin.
 *
 * `AppServiceProvider::registerPlatformAdminGate()` short-circuits
 * Gate::before to true for every ability a platform admin asks about. That
 * is right for role-based permission, but JournalEntryPolicy also folds the
 * entry's STATE into the same check (`&& $entry->isMutable()`), and the
 * short-circuit sails straight past it.
 *
 * Editing or deleting a posted entry is not a permission anyone holds — it
 * would rewrite books that have already been reported on. So the refusal
 * cannot live in the policy alone.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();

    AccountingPeriod::factory()->create([
        'code' => '2026-08', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31',
    ]);

    $this->cash = ChartOfAccount::factory()->asset()->create(['code' => '1100']);
    $this->income = ChartOfAccount::factory()->income()->create(['code' => '4100']);

    $this->platformAdmin = User::factory()->withoutLmsMirror()->create();
    $this->platformAdmin->syncRoles(['platform-admin']);
    $this->platformAdmin = $this->platformAdmin->fresh();
});

function postedEntryForGuard(): JournalEntry
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

    return app(PostJournalEntry::class)
        ->execute($entry->fresh(), (int) test()->platformAdmin->getKey());
}

it('confirms the platform-admin gate does short-circuit the state guard', function () {
    // Documents WHY the controller needs its own guard: the policy's
    // `&& isMutable()` is genuinely bypassed here, so relying on it alone
    // would leave the endpoint open.
    $posted = postedEntryForGuard();

    expect($posted->isMutable())->toBeFalse()
        ->and(Gate::forUser($this->platformAdmin)->allows('update', $posted))
        ->toBeTrue();
});

it('refuses to let a platform admin edit a posted entry', function () {
    $posted = postedEntryForGuard();

    $this->actingAs($this->platformAdmin)
        ->patch("/admin/journal-entries/{$posted->id}", [
            'date' => '2026-08-15',
            'reference' => 'TAMPERED',
            'narration' => 'Rewritten after the fact',
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_centavos' => 1, 'credit_centavos' => 0],
                ['account_id' => $this->income->id, 'debit_centavos' => 0, 'credit_centavos' => 1],
            ],
        ])
        ->assertForbidden();

    $fresh = $posted->fresh();
    expect($fresh->reference)->not()->toBe('TAMPERED')
        ->and($fresh->total_debit_centavos)->toBe(500_000)
        ->and($fresh->lines()->sum('debit_centavos'))->toBe(500_000);
});

it('refuses to let a platform admin delete a posted entry', function () {
    $posted = postedEntryForGuard();

    $this->actingAs($this->platformAdmin)
        ->delete("/admin/journal-entries/{$posted->id}")
        ->assertForbidden();

    expect(JournalEntry::query()->whereKey($posted->id)->exists())->toBeTrue()
        ->and(JournalEntryLine::query()->where('journal_entry_id', $posted->id)->count())->toBe(2);
});

it('refuses to let a platform admin reach the edit form for a posted entry', function () {
    $posted = postedEntryForGuard();

    $this->actingAs($this->platformAdmin)
        ->get("/admin/journal-entries/{$posted->id}/edit")
        ->assertForbidden();
});

it('does not offer illegal actions to a platform admin on the detail page', function () {
    $posted = postedEntryForGuard();

    $this->actingAs($this->platformAdmin)
        ->get("/admin/journal-entries/{$posted->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/accounting/journal/show', false)
            // Posted: nothing to edit, delete, or post. Reversing is the
            // only legal move left.
            ->where('entry.can.update', false)
            ->where('entry.can.delete', false)
            ->where('entry.can.post', false)
            ->where('entry.can.reverse', true));
});

it('still lets a platform admin edit and delete a draft', function () {
    // The guard is about the entry's state, not about distrusting the role.
    $draft = JournalEntry::factory()->create(['date' => '2026-08-15']);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $draft->id, 'account_id' => $this->cash->id,
        'debit_centavos' => 500_000, 'credit_centavos' => 0, 'line_number' => 1,
    ]);
    JournalEntryLine::factory()->create([
        'journal_entry_id' => $draft->id, 'account_id' => $this->income->id,
        'debit_centavos' => 0, 'credit_centavos' => 500_000, 'line_number' => 2,
    ]);

    $this->actingAs($this->platformAdmin)
        ->get("/admin/journal-entries/{$draft->id}/edit")
        ->assertOk();

    $this->actingAs($this->platformAdmin)
        ->delete("/admin/journal-entries/{$draft->id}")
        ->assertRedirect();

    expect(JournalEntry::query()->whereKey($draft->id)->exists())->toBeFalse();
});

/*
 * The same short-circuit reaches the other Slice 1 surfaces. Both of these
 * destroy or reshape things the ledger depends on, so both are guarded
 * outside authorization too.
 */

it('refuses to let a platform admin delete a locked system account', function () {
    $arControl = ChartOfAccount::factory()
        ->asset()
        ->system(ChartOfAccount::SYSTEM_AR_CONTROL)
        ->create(['code' => '1200']);

    // Deleting it would break invoice, bill, payment and payroll posting.
    $this->actingAs($this->platformAdmin)
        ->delete("/admin/chart-of-accounts/{$arControl->getKey()}")
        ->assertForbidden();

    expect(ChartOfAccount::query()->whereKey($arControl->getKey())->exists())->toBeTrue();
});

it('refuses to let a platform admin reshape a closed accounting period', function () {
    $period = AccountingPeriod::query()->first();
    $period->forceFill([
        'status' => AccountingPeriod::STATUS_CLOSED,
        'closed_at' => now(),
    ])->save();

    // Moving the boundaries would silently change which entries it froze.
    $this->actingAs($this->platformAdmin)
        ->patch("/admin/accounting-periods/{$period->getKey()}", [
            'code' => $period->code,
            'name' => 'Stretched',
            'start_date' => '2026-07-01',
            'end_date' => '2026-09-30',
            'fiscal_year' => 2026,
        ])
        ->assertForbidden();

    expect($period->fresh()->start_date->toDateString())->toBe('2026-08-01');
});
