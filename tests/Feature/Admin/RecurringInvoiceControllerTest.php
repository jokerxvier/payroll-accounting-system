<?php

declare(strict_types=1);

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use App\Models\Pas\RecurringInvoice;
use App\Models\Pas\RecurringInvoiceLine;
use App\Models\Pas\School;
use App\Models\User;
use Database\Seeders\AccountingCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/*
 * The schedules screen.
 *
 * Pins the maker-checker split — a schedule is a maker's tool and confers no
 * power to post — and the two rules that stop a schedule being saved in a
 * state the nightly generator would choke on.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AccountingCatalogSeeder::class);

    $this->income = ChartOfAccount::query()->where('code', '4100')->firstOrFail();
    $this->customer = Contact::factory()->create([
        'name' => 'Dela Cruz Family',
        'is_customer' => true,
    ]);
});

function scheduleAuthAs(string $role): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

/** @return array<string, mixed> */
function schedulePayload(array $overrides = [], array $lineOverrides = []): array
{
    return array_merge([
        'name' => 'Grade 7 tuition — Dela Cruz',
        'type' => Invoice::TYPE_SALES,
        'contact_id' => test()->customer->id,
        'lms_student_id' => null,
        'reference' => null,
        'is_vat_inclusive' => false,
        'notes' => null,
        'terms' => null,
        'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
        'day_of_month' => 1,
        'starts_on' => '2026-09-01',
        'ends_on' => null,
        'due_days' => 15,
        'is_active' => true,
        'lines' => [array_merge([
            'description' => 'Tuition fee — Grade 7',
            'quantity' => '1',
            'unit_price_centavos' => 500_000,
            'account_id' => test()->income->id,
            'tax_rate_id' => null,
        ], $lineOverrides)],
    ], $overrides);
}

/* ── Access ──────────────────────────────────────────────────────────── */

/**
 * A sales invoice that also sets up a schedule.
 *
 * Deliberately shaped like the real form: the cadence is the only thing sent,
 * and the day of the month and the payment terms fall out of `issue_date` and
 * `due_date` on the server.
 *
 * @return array<string, mixed>
 */
function repeatingInvoicePayload(array $overrides = [], array $lineOverrides = []): array
{
    return array_merge([
        'type' => Invoice::TYPE_SALES,
        'contact_id' => test()->customer->id,
        'lms_student_id' => null,
        'reference' => null,
        'issue_date' => '2026-09-01',
        'due_date' => '2026-09-16',
        'is_vat_inclusive' => false,
        'notes' => null,
        'terms' => null,
        'repeat' => true,
        'recurrence' => [
            'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
            'name' => 'Grade 7 tuition — Dela Cruz',
            'ends_on' => null,
        ],
        'lines' => [array_merge([
            'description' => 'Tuition fee — Grade 7',
            'quantity' => '1',
            'unit_price_centavos' => 500_000,
            'account_id' => test()->income->id,
            'tax_rate_id' => null,
        ], $lineOverrides)],
    ], $overrides);
}

it('lets every accounting-view role see the list', function (string $role) {
    $this->actingAs(scheduleAuthAs($role))
        ->get(route('admin.recurring-invoices.index'))
        ->assertOk();
})->with(['super-admin', 'accountant', 'payroll-officer', 'auditor']);

it('refuses roles outside the accounting set', function (string $role) {
    $this->actingAs(scheduleAuthAs($role))
        ->get(route('admin.recurring-invoices.index'))
        ->assertForbidden();
})->with(['hr', 'employee']);

it('lets an auditor read but not write', function () {
    $auditor = scheduleAuthAs('auditor');

    $this->actingAs($auditor)->get(route('admin.recurring-invoices.index'))->assertOk();
    $this->actingAs($auditor)
        ->patch(
            route('admin.recurring-invoices.update', RecurringInvoice::factory()->create()),
            schedulePayload(),
        )
        ->assertForbidden();
});

/* ── Creating happens on the invoice form ────────────────────────────── */

it('offers no way to create a schedule on its own', function () {
    // A schedule is set up while raising the first invoice, so that the payer,
    // the student and the lines are typed once — and so the month that invoice
    // covers is claimed and cannot be billed again overnight. The routes that
    // let one be created without a document are gone, not merely unlinked.
    expect(Route::has('admin.recurring-invoices.create'))->toBeFalse()
        ->and(Route::has('admin.recurring-invoices.store'))->toBeFalse();
});

it('still refuses a schedule with no lines, now through the invoice', function () {
    // Every refusal the standalone form used to make is still made — the door
    // moved, the rules did not.
    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload(['lines' => []]))
        ->assertSessionHasErrors('lines');

    expect(RecurringInvoice::query()->count())->toBe(0);
});

it('still refuses a payer who is not a customer', function () {
    $supplier = Contact::factory()->create(['is_customer' => false, 'is_supplier' => true]);

    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload(['contact_id' => $supplier->id]))
        ->assertSessionHasErrors('contact_id');

    expect(RecurringInvoice::query()->count())->toBe(0);
});

it('still refuses an account belonging to another school', function () {
    // The trap a headless generator cannot see: a template line pointing at
    // someone else's chart would post to a foreign account on approval.
    $other = School::factory()->create();
    $foreign = ChartOfAccount::factory()->create(['school_id' => $other->id]);

    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload([], ['account_id' => $foreign->id]))
        ->assertSessionHasErrors('lines.0.account_id');

    expect(RecurringInvoice::query()->count())->toBe(0);
});

it('refuses an end date before the invoice it starts from', function () {
    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload([
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-16',
            'recurrence' => [
                'frequency' => RecurringInvoice::FREQUENCY_MONTHLY,
                'ends_on' => '2026-08-01',
            ],
        ]))
        ->assertSessionHasErrors('recurrence.ends_on');
});

it('refuses a cadence that is not one of the three', function () {
    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload([
            'recurrence' => ['frequency' => 'fortnightly'],
        ]))
        ->assertSessionHasErrors('recurrence.frequency');
});

it('asks how often when repeat is ticked with nothing chosen', function () {
    $payload = repeatingInvoicePayload();
    unset($payload['recurrence']);

    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), $payload)
        ->assertSessionHasErrors('recurrence');
});

it('cannot ask for a day of the month at all', function () {
    // The old form validated `day_of_month` between 1 and 31. It now comes
    // from the invoice's own issue date, so a 32nd is not a value that can be
    // rejected — it is one that cannot be expressed. A client that sends one
    // is ignored rather than obeyed.
    $this->actingAs(scheduleAuthAs('accountant'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload([
            'issue_date' => '2026-09-05',
            'due_date' => '2026-09-20',
            'day_of_month' => 32,
        ]))
        ->assertSessionHasNoErrors();

    expect(RecurringInvoice::query()->sole()->day_of_month)->toBe(5);
});

it('lets a payroll officer set one up, because they may raise the invoice', function () {
    // A schedule raises drafts and posts nothing, so it needs only MANAGE —
    // the same authority as drafting an invoice by hand.
    $this->actingAs(scheduleAuthAs('payroll-officer'))
        ->post(route('admin.invoices.store'), repeatingInvoicePayload())
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(RecurringInvoice::query()->count())->toBe(1);
});

/* ── Editing ─────────────────────────────────────────────────────────── */

it('leaves the run cursor alone when a schedule is edited', function () {
    // Recomputing next_run_on from an edited start date would re-open periods
    // the schedule has already billed.
    $schedule = RecurringInvoice::factory()->create([
        'contact_id' => $this->customer->id,
        'starts_on' => '2026-08-01',
        'next_run_on' => '2026-11-01',
    ]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')
        ->create(['account_id' => $this->income->id]);

    $this->actingAs(scheduleAuthAs('accountant'))
        ->put(
            route('admin.recurring-invoices.update', $schedule),
            schedulePayload(['name' => 'Renamed', 'starts_on' => '2026-08-01']),
        )
        ->assertSessionHasNoErrors();

    expect($schedule->refresh()->name)->toBe('Renamed')
        ->and($schedule->next_run_on->toDateString())->toBe('2026-11-01');
});

/* ── Pausing ─────────────────────────────────────────────────────────── */

it('pauses and resumes a schedule', function () {
    $schedule = RecurringInvoice::factory()->create(['contact_id' => $this->customer->id]);
    $user = scheduleAuthAs('accountant');

    $this->actingAs($user)->post(route('admin.recurring-invoices.pause', $schedule));
    expect($schedule->refresh()->is_active)->toBeFalse();

    $this->actingAs($user)->post(route('admin.recurring-invoices.resume', $schedule));
    expect($schedule->refresh()->is_active)->toBeTrue();
});

it('does not let an auditor pause a schedule', function () {
    $schedule = RecurringInvoice::factory()->create(['contact_id' => $this->customer->id]);

    $this->actingAs(scheduleAuthAs('auditor'))
        ->post(route('admin.recurring-invoices.pause', $schedule))
        ->assertForbidden();
});

/* ── Deleting ────────────────────────────────────────────────────────── */

it('deletes a schedule and its lines, leaving its invoices alone', function () {
    $schedule = RecurringInvoice::factory()->create(['contact_id' => $this->customer->id]);
    RecurringInvoiceLine::factory()->for($schedule, 'recurringInvoice')
        ->create(['account_id' => $this->income->id]);

    $invoice = Invoice::factory()->create([
        'contact_id' => $this->customer->id,
        'recurring_invoice_id' => $schedule->id,
    ]);

    $this->actingAs(scheduleAuthAs('accountant'))
        ->delete(route('admin.recurring-invoices.destroy', $schedule))
        ->assertRedirect();

    expect(RecurringInvoice::query()->count())->toBe(0)
        ->and(RecurringInvoiceLine::query()->count())->toBe(0)
        // The document survives; only its link to the schedule is cleared.
        ->and($invoice->refresh()->exists)->toBeTrue()
        ->and($invoice->recurring_invoice_id)->toBeNull();
});
