<?php

declare(strict_types=1);

namespace Database\Factories\Pas;

use App\Models\Pas\Contact;
use App\Models\Pas\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * Default: an unnumbered sales draft with zero totals and no lines.
     *
     * No number, for the same reason JournalEntryFactory invents none —
     * serials are allocated at approval, the unique is on
     * (school_id, type, number), and a factory that made them up would
     * collide the moment two drafts existed.
     *
     * Totals are zero because they are the calculator's output, not input.
     * A factory that set them independently of the lines would let a test
     * assert against figures no calculation ever produced.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => Invoice::TYPE_SALES,
            'contact_id' => Contact::factory(),
            'number' => null,
            'reference' => null,
            'issue_date' => CarbonImmutable::now()->startOfDay(),
            'due_date' => CarbonImmutable::now()->startOfDay()->addDays(30),
            'status' => Invoice::STATUS_DRAFT,
            'is_vat_inclusive' => false,
            'vatable_sales_centavos' => 0,
            'vat_exempt_sales_centavos' => 0,
            'zero_rated_sales_centavos' => 0,
            'vat_centavos' => 0,
            'total_centavos' => 0,
            'amount_paid_centavos' => 0,
            'notes' => null,
            'terms' => null,
            'journal_entry_id' => null,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'sent_at' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason' => null,
        ];
    }

    public function sales(): self
    {
        return $this->state(fn (): array => ['type' => Invoice::TYPE_SALES]);
    }

    public function purchase(): self
    {
        return $this->state(fn (): array => [
            'type' => Invoice::TYPE_PURCHASE,
            'contact_id' => Contact::factory()->supplier(),
        ]);
    }

    public function vatInclusive(): self
    {
        return $this->state(fn (): array => ['is_vat_inclusive' => true]);
    }

    public function on(CarbonImmutable $date): self
    {
        return $this->state(fn (): array => ['issue_date' => $date]);
    }

    /**
     * Totals for a straightforward VATable sale, consistent by construction.
     *
     * Takes the *net* and derives VAT at 12%, so the invariant
     * `total = vatable + exempt + zero_rated + vat` holds. Use this for
     * fixtures that need a plausible invoice; tests of the calculator itself
     * must build lines and run it.
     */
    public function withTotals(int $netCentavos, int $vatCentavos = 0): self
    {
        return $this->state(fn (): array => [
            'vatable_sales_centavos' => $netCentavos,
            'vat_centavos' => $vatCentavos,
            'total_centavos' => $netCentavos + $vatCentavos,
        ]);
    }

    /**
     * An already-approved invoice carrying a number.
     *
     * Stamps directly rather than going through ApproveInvoice — for tests
     * that need an issued document as a fixture. Tests of approval itself
     * must call the action, or they prove nothing about it.
     */
    public function approved(int $netCentavos = 1_000_000, int $vatCentavos = 120_000): self
    {
        return $this->withTotals($netCentavos, $vatCentavos)->state(fn (): array => [
            'status' => Invoice::STATUS_APPROVED,
            'number' => 'SI-'.fake()->unique()->numerify('######'),
            'approved_at' => CarbonImmutable::now(),
        ]);
    }

    public function paid(): self
    {
        return $this->approved()->state(fn (array $attributes): array => [
            'status' => Invoice::STATUS_PAID,
            'amount_paid_centavos' => $attributes['total_centavos'],
        ]);
    }

    /**
     * A voided invoice. Note it keeps its number — the serial stays
     * accounted for rather than being released for reuse.
     */
    public function voided(): self
    {
        return $this->approved()->state(fn (): array => [
            'status' => Invoice::STATUS_VOIDED,
            'voided_at' => CarbonImmutable::now(),
            'void_reason' => fake()->sentence(),
        ]);
    }
}
