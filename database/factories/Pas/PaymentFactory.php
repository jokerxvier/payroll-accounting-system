<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\ChartOfAccount;
use App\Models\Pas\Contact;
use App\Models\Pas\Payment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * Default: an unposted ₱1,000 receipt with nothing allocated.
     *
     * `allocated_centavos` is zero because it is ApplyPaymentAllocations'
     * output, not input. A factory that set it independently of the
     * allocation rows would let a test assert against a figure no
     * allocation ever produced.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Payment::TYPE_RECEIPT,
            'contact_id' => Contact::factory(),
            'payment_date' => CarbonImmutable::now()->startOfDay(),
            'amount_centavos' => 100_000,
            'allocated_centavos' => 0,
            'cash_account_id' => ChartOfAccount::factory()->asset(),
            'method' => Payment::METHOD_CASH,
            'reference' => null,
            'notes' => null,
            'status' => Payment::STATUS_DRAFT,
            'journal_entry_id' => null,
            'posted_at' => null,
            'posted_by_user_id' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ];
    }

    public function receipt(): self
    {
        return $this->state(fn (): array => ['type' => Payment::TYPE_RECEIPT]);
    }

    public function disbursement(): self
    {
        return $this->state(fn (): array => [
            'type' => Payment::TYPE_DISBURSEMENT,
            'contact_id' => Contact::factory()->supplier(),
        ]);
    }

    public function amount(int $centavos): self
    {
        return $this->state(fn (): array => ['amount_centavos' => $centavos]);
    }

    public function on(CarbonImmutable $date): self
    {
        return $this->state(fn (): array => ['payment_date' => $date]);
    }

    /**
     * An already-posted payment.
     *
     * Stamps directly rather than going through PostPayment — for tests that
     * need a posted row as a fixture. Tests of posting itself must call the
     * action, or they prove nothing about it.
     */
    public function posted(): self
    {
        return $this->state(fn (): array => [
            'status' => Payment::STATUS_POSTED,
            'posted_at' => CarbonImmutable::now(),
        ]);
    }

    public function voided(): self
    {
        return $this->posted()->state(fn (): array => [
            'status' => Payment::STATUS_VOIDED,
            'voided_at' => CarbonImmutable::now(),
            'void_reason' => fake()->sentence(),
        ]);
    }
}
