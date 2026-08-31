<?php

declare(strict_types=1);

use App\Models\Pas\AccountingPeriod;
use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\GatewayWebhookEvent;
use App\Models\Pas\Invoice;
use App\Models\Pas\JournalEntry;
use App\Models\Pas\JournalEntryLine;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentGatewaySetting;
use App\Models\Pas\School;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingCatalogSeeder;

/*
 * The webhook — the first unauthenticated POST this application has ever
 * accepted.
 *
 * Anyone can reach this URL. What separates a real delivery from somebody
 * marking their own invoice paid is an HMAC over the raw body, so the
 * signature tests here are not one property among several — they are the
 * whole security model. The tenancy test matters nearly as much: a webhook is
 * the one request shape where `BelongsToTenant` could run with no tenant
 * current, and it fails OPEN.
 */

beforeEach(function (): void {
    JournalEntryLine::query()->withoutGlobalScopes()->delete();
    JournalEntry::query()->withoutGlobalScopes()->delete();
    AccountingPeriod::query()->withoutGlobalScopes()->delete();
    ChartOfAccount::query()->withoutGlobalScopes()->delete();
    GatewayWebhookEvent::query()->delete();

    $this->seed(AccountingCatalogSeeder::class);
    AccountingPeriod::factory()->forMonth(CarbonImmutable::now()->startOfMonth())->create();

    $this->school = School::query()->where('slug', 'default')->firstOrFail();
    $this->cash = ChartOfAccount::query()->where('code', '1110')->firstOrFail();
    $this->fee = ChartOfAccount::query()->where('code', '5900')->firstOrFail();

    $this->setting = PaymentGatewaySetting::factory()
        ->usable($this->cash, $this->fee)
        ->create([
            'webhook_secret' => 'whsec_topsecret',
            'mode' => PaymentGatewaySetting::MODE_TEST,
        ]);

    $this->customer = Contact::factory()->customer()->create();

    $this->invoice = Invoice::factory()->create([
        'contact_id' => $this->customer->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'number' => 'INV-2026-00001',
        'issue_date' => CarbonImmutable::now()->toDateString(),
        'total_centavos' => 112_000,
        'amount_paid_centavos' => 0,
    ]);
});

/** A PayMongo `payment.paid` body for the seeded invoice. */
function paymongoBody(int $invoiceId, string $eventId = 'evt_1', int $gross = 112_000, int $fee = 2_800): string
{
    return json_encode([
        'data' => [
            'id' => $eventId,
            'attributes' => [
                'type' => 'payment.paid',
                'data' => [
                    'id' => 'pay_abc',
                    'attributes' => [
                        'amount' => $gross,
                        'fee' => $fee,
                        'metadata' => ['invoice_id' => (string) $invoiceId],
                    ],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);
}

function signPaymongo(string $body, string $secret = 'whsec_topsecret'): string
{
    $t = '1799999999';

    return sprintf('t=%s,te=%s', $t, hash_hmac('sha256', $t.'.'.$body, $secret));
}

function postWebhook(string $body, ?string $signature, string $provider = 'paymongo')
{
    return test()->call(
        'POST',
        "/schools/default/webhooks/{$provider}",
        [],
        [],
        [],
        $signature === null ? [] : ['HTTP_PAYMONGO_SIGNATURE' => $signature],
        $body,
    );
}

/* ── The security boundary ──────────────────────────────────────────── */

it('refuses a delivery with no signature and records the attempt', function (): void {
    $body = paymongoBody((int) $this->invoice->getKey());

    postWebhook($body, null)->assertStatus(401);

    expect(Payment::query()->count())->toBe(0)
        ->and($this->invoice->refresh()->status)->toBe(Invoice::STATUS_APPROVED)
        // Recorded rather than dropped — a rejected delivery is exactly the
        // evidence worth having after an incident.
        ->and(GatewayWebhookEvent::query()->where('status', 'failed')->count())->toBe(1);
});

it('refuses a body altered after it was signed', function (): void {
    $signed = paymongoBody((int) $this->invoice->getKey(), gross: 100);
    $signature = signPaymongo($signed);

    // Same signature, much larger amount.
    $tampered = paymongoBody((int) $this->invoice->getKey(), gross: 10_000_000);

    postWebhook($tampered, $signature)->assertStatus(401);

    expect(Payment::query()->count())->toBe(0);
});

it('refuses a signature made with someone else\'s secret', function (): void {
    $body = paymongoBody((int) $this->invoice->getKey());

    postWebhook($body, signPaymongo($body, 'whsec_attacker'))->assertStatus(401);

    expect(Payment::query()->count())->toBe(0);
});

/* ── Doing the job ──────────────────────────────────────────────────── */

it('records and posts a receipt from a signed delivery', function (): void {
    $body = paymongoBody((int) $this->invoice->getKey());

    postWebhook($body, signPaymongo($body))->assertOk();

    $payment = Payment::query()->sole();

    expect($payment->status)->toBe(Payment::STATUS_POSTED)
        ->and($payment->method)->toBe(Payment::METHOD_ONLINE)
        ->and($payment->amount_centavos)->toBe(112_000)
        ->and($payment->fee_centavos)->toBe(2_800)
        ->and($payment->gateway_reference)->toBe('pay_abc')
        // Nobody clicked anything.
        ->and($payment->posted_by_user_id)->toBeNull()
        ->and($this->invoice->refresh()->status)->toBe(Invoice::STATUS_PAID)
        ->and(GatewayWebhookEvent::query()->sole()->status)->toBe('handled');
});

it('posts cash net of the fee, with the invoice cleared in full', function (): void {
    $body = paymongoBody((int) $this->invoice->getKey());
    postWebhook($body, signPaymongo($body))->assertOk();

    $entry = JournalEntry::query()->where('source_type', Payment::class)->sole();
    $byCode = [];
    foreach ($entry->lines()->get() as $line) {
        $byCode[ChartOfAccount::query()->find($line->account_id)->code] = $line;
    }

    expect($byCode['1110']->debit_centavos)->toBe(109_200)
        ->and($byCode['5900']->debit_centavos)->toBe(2_800)
        ->and($byCode['1200']->credit_centavos)->toBe(112_000);
});

/* ── Idempotency ────────────────────────────────────────────────────── */

it('creates one payment however many times the same event is delivered', function (): void {
    $body = paymongoBody((int) $this->invoice->getKey());
    $signature = signPaymongo($body);

    postWebhook($body, $signature)->assertOk();
    postWebhook($body, $signature)->assertOk();
    postWebhook($body, $signature)->assertOk();

    // Gateways retry until they get a 2xx and can redeliver after one. Three
    // deliveries of one event must not be three payments.
    expect(Payment::query()->count())->toBe(1)
        ->and(GatewayWebhookEvent::query()->count())->toBe(1)
        ->and($this->invoice->refresh()->amount_paid_centavos)->toBe(112_000);
});

it('treats a second, genuinely different payment as an overpayment', function (): void {
    $first = paymongoBody((int) $this->invoice->getKey(), 'evt_1');
    postWebhook($first, signPaymongo($first))->assertOk();

    $second = paymongoBody((int) $this->invoice->getKey(), 'evt_2');
    postWebhook($second, signPaymongo($second))->assertOk();

    // Paying the same link twice is the customer's mistake, not ours: the
    // money arrived, so it is recorded, and the surplus is an advance rather
    // than a negative receivable.
    expect(Payment::query()->count())->toBe(2)
        ->and($this->invoice->refresh()->amount_paid_centavos)->toBe(112_000)
        ->and($this->invoice->status)->toBe(Invoice::STATUS_PAID);
});

/* ── Tenancy ────────────────────────────────────────────────────────── */

it('will not settle another school\'s invoice', function (): void {
    $other = School::factory()->create(['slug' => 'other']);
    $foreign = Invoice::factory()->create([
        'school_id' => $other->getKey(),
        'type' => Invoice::TYPE_SALES,
        'status' => Invoice::STATUS_APPROVED,
        'total_centavos' => 500_000,
    ]);

    // Signed correctly for THIS school, naming a document belonging to another.
    $body = paymongoBody((int) $foreign->getKey());

    postWebhook($body, signPaymongo($body))->assertStatus(202);

    expect(Payment::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($foreign->refresh()->status)->toBe(Invoice::STATUS_APPROVED)
        ->and(GatewayWebhookEvent::query()->sole()->message)
        ->toContain('No invoice for this event in this school');
});

/* ── Events we do not act on ────────────────────────────────────────── */

it('records a refund without posting anything', function (): void {
    $body = json_encode([
        'data' => [
            'id' => 'evt_refund',
            'attributes' => [
                'type' => 'payment.refunded',
                'data' => ['id' => 'pay_x', 'attributes' => [
                    'amount' => 112_000,
                    'metadata' => ['invoice_id' => (string) $this->invoice->getKey()],
                ]],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    postWebhook($body, signPaymongo($body))->assertOk();

    // A refund posted as a receipt would be worse than one not posted at all.
    expect(Payment::query()->count())->toBe(0)
        ->and(GatewayWebhookEvent::query()->sole()->status)->toBe('ignored');
});

it('refuses an unknown provider outright', function (): void {
    postWebhook('{}', 'irrelevant', 'someothergateway')->assertStatus(404);
});
