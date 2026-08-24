<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Invoice;
use App\Models\Pas\Payment;
use App\Models\Pas\PaymentAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentAllocation>
 */
class PaymentAllocationFactory extends Factory
{
    protected $model = PaymentAllocation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'invoice_id' => Invoice::factory(),
            'amount_centavos' => 100_000,
        ];
    }

    public function forPayment(Payment $payment): self
    {
        return $this->state(fn (): array => [
            'payment_id' => $payment->getKey(),
            'school_id' => $payment->school_id,
        ]);
    }

    public function forInvoice(Invoice $invoice): self
    {
        return $this->state(fn (): array => ['invoice_id' => $invoice->getKey()]);
    }

    public function amount(int $centavos): self
    {
        return $this->state(fn (): array => ['amount_centavos' => $centavos]);
    }
}
